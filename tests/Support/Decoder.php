<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests\Support;

/**
 * The external opinion on whether our symbols actually scan.
 *
 * Every encoder in this library is verified against tables transcribed from
 * the same standards the encoder implements, which cannot catch a table that
 * is wrong in the same direction as its test. This bridge shells out to
 * zxing-cpp — an independent reference decoder — so "it works" means a scanner
 * read the payload back, not that our own arithmetic agreed with itself.
 *
 * Set SCANME_REQUIRE_DECODER=1 (CI does) to turn a missing decoder into a
 * failure instead of a skip: a gate that silently disappears is worse than no
 * gate, because the suite still reports green.
 */
final class Decoder
{
    /** Populated once per process; probing costs an interpreter start-up. */
    private static ?string $python = null;
    private static bool $probed = false;

    /** Set when SCANME_DECODER_PYTHON names an interpreter that cannot decode. */
    private static ?string $rejectedOverride = null;

    public static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 2);
    }

    private static function script(): string
    {
        return self::repositoryRoot() . '/tools/decode.py';
    }

    private static function override(): ?string
    {
        $override = getenv('SCANME_DECODER_PYTHON');

        return \is_string($override) && $override !== '' ? $override : null;
    }

    /**
     * Interpreters to try when no override is set: the repository's own
     * virtualenv first, then whatever is on PATH.
     *
     * @return list<string>
     */
    private static function candidates(): array
    {
        return [self::repositoryRoot() . '/.decoders/bin/python', 'python3'];
    }

    public static function isAvailable(): bool
    {
        return self::python() !== null;
    }

    private static function python(): ?string
    {
        if (self::$probed) {
            return self::$python;
        }

        self::$probed = true;

        if (!is_file(self::script())) {
            return self::$python = null;
        }

        // An explicit override is a statement of intent: if it cannot decode,
        // say so rather than quietly using a different interpreter and
        // reporting results the caller did not ask for.
        $override = self::override();
        if ($override !== null) {
            if (self::probe($override)) {
                return self::$python = $override;
            }

            self::$rejectedOverride = $override;

            return self::$python = null;
        }

        foreach (self::candidates() as $candidate) {
            if (self::probe($candidate)) {
                return self::$python = $candidate;
            }
        }

        return self::$python = null;
    }

    /** Can this interpreter import both libraries the script needs? */
    private static function probe(string $python): bool
    {
        $command = sprintf(
            '%s -c %s 2>/dev/null',
            escapeshellarg($python),
            escapeshellarg('import zxingcpp, PIL')
        );

        exec($command, $output, $status);

        return $status === 0;
    }

    /**
     * The message shown when the decoder is missing, naming the fix rather
     * than just the symptom.
     */
    public static function unavailableReason(): string
    {
        // Force the probe so a rejected override can be named.
        self::python();

        if (self::$rejectedOverride !== null) {
            return sprintf(
                'SCANME_DECODER_PYTHON points at "%s", which cannot import zxing-cpp and pillow',
                self::$rejectedOverride
            );
        }

        return 'zxing-cpp decoder unavailable; run `composer decoders:install` '
            . '(or set SCANME_DECODER_PYTHON to an interpreter that has zxing-cpp and pillow)';
    }

    /**
     * Decode a PNG and return every symbol found in it.
     *
     * @param string|null $formats Restrict the decoder to these zxing-cpp
     *        formats, e.g. 'UPCA'. Null lets it report whatever it finds,
     *        which for the EAN/UPC family is not always the symbology that
     *        was asked for: UPC-A shares its bars with EAN-13.
     * @param string|null $eanAddOn 'ignore', 'read' or 'require' — what to do
     *        with a two- or five-digit add-on printed beside an EAN/UPC
     *        symbol. 'require' refuses a symbol that has none, which is the
     *        only way to make a scanner confirm add-on bars: zxing-cpp has no
     *        reader for a lone EAN-2 or EAN-5.
     * @return list<array{format: string, text: string, bytes: list<int>, valid: bool}>
     */
    public static function decode(string $png, ?string $formats = null, ?string $eanAddOn = null): array
    {
        $python = self::python();
        if ($python === null) {
            throw new \RuntimeException(self::unavailableReason());
        }

        $file = tempnam(sys_get_temp_dir(), 'scanme-rt-') . '.png';
        file_put_contents($file, $png);

        try {
            $command = sprintf(
                '%s %s %s%s%s 2>&1',
                escapeshellarg($python),
                escapeshellarg(self::script()),
                $formats === null ? '' : '--formats ' . escapeshellarg($formats) . ' ',
                $eanAddOn === null ? '' : '--ean-add-on ' . escapeshellarg($eanAddOn) . ' ',
                escapeshellarg($file)
            );

            exec($command, $lines, $status);
            $raw = implode("\n", $lines);

            if ($status !== 0) {
                throw new \RuntimeException("decoder failed (exit {$status}): {$raw}");
            }

            /** @var array{symbols?: list<array<string, mixed>>, error?: string}|null $decoded */
            $decoded = json_decode($raw, true);
            if (!\is_array($decoded)) {
                throw new \RuntimeException("decoder returned unparseable output: {$raw}");
            }
            if (isset($decoded['error'])) {
                throw new \RuntimeException('decoder error: ' . $decoded['error']);
            }

            /** @var list<array{format: string, text: string, bytes: list<int>, valid: bool}> $symbols */
            $symbols = $decoded['symbols'] ?? [];

            return $symbols;
        } finally {
            @unlink($file);
        }
    }
}
