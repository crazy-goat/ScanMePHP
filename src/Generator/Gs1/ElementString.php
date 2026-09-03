<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Gs1;

use CrazyGoat\ScanMePHP\Generator\Code128\Encoder;

/**
 * A GS1 payload: application identifiers paired with their data.
 *
 * Written the way GS1 writes it for people to read —
 * '(01)09501101020917(10)LOT0001' — which is also the form printed under the
 * bars. The parentheses are not in the symbol. What the symbol carries is the
 * identifiers and data run together, with an FNC1 wherever one element string
 * ends and the scanner could not otherwise tell.
 *
 * Deciding where those FNC1s go is the whole job, and getting it wrong does not
 * produce an unreadable symbol. It produces a readable one that says something
 * else: a missing separator makes the next identifier look like more of the
 * previous element's data. So the rule comes from ApplicationIdentifier, whose
 * table was derived from an implementation that is not ours.
 *
 * The parenthesised form cannot express data containing a parenthesis. GS1's
 * character set does permit one, so this is a real limit rather than a rule of
 * the standard — it is refused with a message saying so, which beats parsing it
 * into something the caller did not write.
 */
final class ElementString
{
    /** @param non-empty-list<array{string, string}> $elements Identifier and its data */
    private function __construct(public readonly array $elements)
    {
    }

    /**
     * @throws \InvalidArgumentException on anything that is not a GS1 payload
     */
    public static function parse(string $data): self
    {
        $elements = [];
        $position = 0;
        $length = \strlen($data);

        while ($position < $length) {
            if ($data[$position] !== '(') {
                throw new \InvalidArgumentException(sprintf(
                    'Expected an application identifier in parentheses at position %d of "%s"',
                    $position,
                    $data
                ));
            }

            $close = strpos($data, ')', $position);
            if ($close === false) {
                throw new \InvalidArgumentException(
                    sprintf('Unclosed application identifier at position %d of "%s"', $position, $data)
                );
            }

            $ai = substr($data, $position + 1, $close - $position - 1);
            if (!ctype_digit($ai) || !ApplicationIdentifier::exists($ai)) {
                throw new \InvalidArgumentException(sprintf('Not a GS1 application identifier: (%s)', $ai));
            }

            $next = strpos($data, '(', $close);
            $value = $next === false
                ? substr($data, $close + 1)
                : substr($data, $close + 1, $next - $close - 1);

            if (str_contains($value, ')')) {
                throw new \InvalidArgumentException(sprintf(
                    'Data for (%s) contains a parenthesis, which this notation cannot express; '
                    . 'GS1 permits one but there is no way to tell it from the next identifier',
                    $ai
                ));
            }

            if (!ApplicationIdentifier::accepts($ai, \strlen($value))) {
                throw new \InvalidArgumentException(sprintf(
                    '(%s) takes %s characters of data, got %d',
                    $ai,
                    self::describeLengths($ai),
                    \strlen($value)
                ));
            }

            $elements[] = [$ai, $value];
            $position = $next === false ? $length : $next;
        }

        if ($elements === []) {
            throw new \InvalidArgumentException('A GS1 payload needs at least one element string');
        }

        return new self($elements);
    }

    /** Whether parse() would succeed, for a caller that would rather ask than catch. */
    public static function isParsable(string $data): bool
    {
        try {
            self::parse($data);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * What a scanner reports: the element strings run together, FNC1 between
     * the ones that need it.
     *
     * The FNC1 that marks the symbol as GS1 is not here. It sits directly after
     * the start code, is a symbol character rather than data, and never reaches
     * the host — which is why this is byte for byte what a reader hands back.
     */
    public function payload(): string
    {
        $payload = '';
        $last = \count($this->elements) - 1;

        foreach ($this->elements as $index => [$ai, $value]) {
            $payload .= $ai . $value;

            // Nothing follows the final element, so nothing has to be
            // separated from it; a trailing FNC1 would be a wasted character.
            if ($index !== $last && ApplicationIdentifier::needsSeparator($ai)) {
                $payload .= Encoder::FNC1;
            }
        }

        return $payload;
    }

    /** The parenthesised form, which is what goes under the bars. */
    public function humanReadable(): string
    {
        $text = '';
        foreach ($this->elements as [$ai, $value]) {
            $text .= '(' . $ai . ')' . $value;
        }

        return $text;
    }

    private static function describeLengths(string $ai): string
    {
        $lengths = ApplicationIdentifier::lengths($ai);

        if (\count($lengths) === 1) {
            return 'exactly ' . $lengths[0];
        }

        return $lengths === range($lengths[0], $lengths[\count($lengths) - 1])
            ? sprintf('%d to %d', $lengths[0], $lengths[\count($lengths) - 1])
            : implode(' or ', $lengths);
    }
}
