<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Code128\Backend;

use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\Code128\CodeSet;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * Code 128 in pure PHP.
 *
 * There is nothing here for a native backend to accelerate: a symbol is a
 * handful of table lookups and a modulo, with no error correction and no mask
 * evaluation, so it encodes in microseconds and the C++ core stays QR-only.
 */
final class PhpBackend implements BackendInterface
{
    /**
     * Element widths for symbol values 0–105, as bar/space/bar/space/bar/space.
     * Every pattern spans 11 modules and its bars span an even number of them,
     * which is the parity rule a scanner uses to reject a misread character.
     * Source: ISO/IEC 15417 Table 1.
     */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232',
    ];

    /** The stop pattern is the one 13-module character, with a trailing bar. */
    private const STOP = '2331112';

    private const CODE_C = 99;

    private const CODE_B = 100;

    private const START_B = 104;

    private const START_C = 105;

    /** Checksum modulus, i.e. the number of symbol values excluding stop. */
    private const CHECKSUM_MODULUS = 103;

    /** Minimum quiet zone either side, in modules (ISO/IEC 15417 §5.3). */
    private const QUIET_ZONE = 10;

    /**
     * Default bar height in modules. The standard states height as a fraction
     * of length rather than a module count, so this is a legible default that
     * render options are expected to override for print.
     */
    private const BAR_HEIGHT = 50;

    public function getName(): string
    {
        return 'php';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function encode(string $data, ?GeneratorOptionsInterface $options = null): Symbol
    {
        $modules = '';
        foreach ($this->symbolValues($data) as $value) {
            $modules .= $this->widthsToModules(self::PATTERNS[$value]);
        }
        $modules .= $this->widthsToModules(self::STOP);

        return Symbol::linear(
            modules: $modules,
            quietZone: new QuietZone(left: self::QUIET_ZONE, right: self::QUIET_ZONE),
            barHeight: self::BAR_HEIGHT,
            text: $data,
            metadata: ['symbology' => Symbology::Code128->value],
        );
    }

    /**
     * The symbol characters for $data: a start code, the payload, and the
     * check character.
     *
     * @return list<int>
     */
    public function symbolValues(string $data): array
    {
        $length = \strlen($data);
        $mode = $this->startCodeSet($data);
        $values = [$mode === CodeSet::C ? self::START_C : self::START_B];

        $position = 0;
        while ($position < $length) {
            if ($mode === CodeSet::C) {
                if ($this->isDigitPairAt($data, $position)) {
                    $values[] = (int) substr($data, $position, 2);
                    $position += 2;

                    continue;
                }

                // A lone digit or a non-digit ends the pair run.
                $values[] = self::CODE_B;
                $mode = CodeSet::B;

                continue;
            }

            // Switching costs one symbol character and saves one per digit
            // pair, so it pays off from six digits — or from four when the run
            // ends the payload and no switch back is needed.
            $run = $this->digitRunLength($data, $position);
            if ($run >= 6 || ($run >= 4 && $run % 2 === 0 && $position + $run === $length)) {
                $values[] = self::CODE_C;
                $mode = CodeSet::C;

                continue;
            }

            $values[] = \ord($data[$position]) - 32;
            $position++;
        }

        $values[] = $this->checkCharacter($values);

        return $values;
    }

    /**
     * Start in set C when it can pay for itself: an all-digit payload of even
     * length, or a run of at least four digits at the front.
     */
    private function startCodeSet(string $data): CodeSet
    {
        $length = \strlen($data);
        $run = $this->digitRunLength($data, 0);

        if ($run === $length && $length >= 2 && $length % 2 === 0) {
            return CodeSet::C;
        }

        return $run >= 4 ? CodeSet::C : CodeSet::B;
    }

    /** @param list<int> $values Start code followed by the payload */
    private function checkCharacter(array $values): int
    {
        // Weighted modulo 103: the start code counts once, then each payload
        // character by its one-based position.
        $sum = $values[0];
        for ($position = 1, $count = \count($values); $position < $count; $position++) {
            $sum += $position * $values[$position];
        }

        return $sum % self::CHECKSUM_MODULUS;
    }

    private function isDigitPairAt(string $data, int $position): bool
    {
        return $position + 1 < \strlen($data)
            && $data[$position] >= '0' && $data[$position] <= '9'
            && $data[$position + 1] >= '0' && $data[$position + 1] <= '9';
    }

    private function digitRunLength(string $data, int $position): int
    {
        $run = 0;
        for ($i = $position, $length = \strlen($data); $i < $length; $i++) {
            if ($data[$i] < '0' || $data[$i] > '9') {
                break;
            }
            $run++;
        }

        return $run;
    }

    /** Element widths to '1'/'0' modules, starting with a bar. */
    private function widthsToModules(string $widths): string
    {
        $modules = '';
        for ($i = 0, $length = \strlen($widths); $i < $length; $i++) {
            $modules .= str_repeat($i % 2 === 0 ? '1' : '0', (int) $widths[$i]);
        }

        return $modules;
    }
}
