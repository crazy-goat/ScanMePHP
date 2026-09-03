<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataBarExpanded\Backend;

use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\DataBar\Patterns;
use CrazyGoat\ScanMePHP\Generator\DataBarExpanded\Encodation\Encodation;
use CrazyGoat\ScanMePHP\Generator\DataBarExpanded\Encodation\GeneralField;
use CrazyGoat\ScanMePHP\Generator\Gs1\ElementString;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * GS1 DataBar Expanded in pure PHP.
 *
 * The only member of the family that carries arbitrary GS1 data rather than a
 * GTIN, and the only one whose width depends on what you put in it: four to
 * twenty-two symbol characters, arranged in pairs with a finder between the two
 * of each pair.
 *
 *     guard  char finder char  char finder char  ...  guard
 *
 * The first character is the check character. Everything after it is the bit
 * stream {@see Encodation} produces, cut into twelve-bit pieces — which is why
 * a symbol is one character longer than its data needs and why the padding
 * pattern matters: a reader has no length field to consult beyond the two
 * variable-length bits, so the filler has to be recognisable as filler.
 *
 * Two things about the layout are measured rather than assumed, because both
 * produce a plausible symbol when wrong:
 *
 *   * A character's widths are drawn forward for the left of a pair and
 *     reversed for the right. The finder between them is reversed too, but only
 *     in odd-numbered pairs.
 *   * The symbol is a single alternating run of elements from the opening guard
 *     space to the closing one, so nothing decides whether a character's first
 *     element is a bar or a space except where the character sits. The
 *     character table's two rows are element positions, not colours; see
 *     {@see Patterns::EXPANDED}.
 *
 * The finder sequence is not the checksum here, as it is in Omnidirectional and
 * Limited. It is a function of the character count alone, and it exists so that
 * a scanner reading one pair out of a stacked symbol knows which pair it read.
 */
final class PhpBackend implements BackendInterface
{
    /** Modules in a data character, four bars and four spaces. */
    public const CHARACTER_MODULES = 17;

    /** Modules in a finder pattern. */
    public const FINDER_MODULES = 15;

    /** Modules in the two guard patterns together. */
    public const GUARD_MODULES = 4;

    /**
     * Height in modules.
     *
     * 34X, which is what GS1 asks of an omnidirectional DataBar wide enough to
     * need it: the height a scan line can be off by and still cross a whole
     * pair.
     */
    public const BAR_HEIGHT = 34;

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
        $elements = ElementString::parse($data);

        $values = Encodation::values($elements);
        $characters = array_map(
            static fn (int $value): array => Patterns::character($value, Patterns::EXPANDED, false),
            $values
        );

        $checksum = Patterns::expandedChecksum($characters);
        $check = Patterns::EXPANDED_MODULUS * (\count($characters) - 3) + $checksum;

        $all = [Patterns::character($check, Patterns::EXPANDED, false), ...$characters];
        $widths = $this->layout($all);

        return Symbol::linear(
            modules: Patterns::modules($widths),
            // None, as everywhere in this family: the guard patterns do a quiet
            // zone's work and the standard asks for no margin beyond them.
            quietZone: QuietZone::none(),
            barHeight: self::BAR_HEIGHT,
            text: $elements->humanReadable(),
            metadata: [
                'symbology' => Symbology::DataBarExpanded->value,
                'characters' => \count($all),
                'checksum' => $checksum,
                'checkCharacter' => $check,
            ],
        );
    }

    /**
     * The whole symbol's element widths, guards included.
     *
     * @param list<list<int>> $characters the check character first
     * @return list<int>
     */
    private function layout(array $characters): array
    {
        $count = \count($characters);
        $pairs = intdiv($count + 1, 2);
        $sequence = Patterns::EXPANDED_FINDER_SEQUENCES[$pairs];

        $widths = [1, 1];

        for ($pair = 0; $pair < $pairs; $pair++) {
            $finder = Patterns::EXPANDED_FINDERS[$sequence[$pair]];

            $widths = [
                ...$widths,
                ...$characters[2 * $pair],
                ...($pair % 2 === 1 ? Patterns::mirror($finder) : $finder),
            ];

            if (2 * $pair + 1 < $count) {
                $widths = [...$widths, ...Patterns::mirror($characters[2 * $pair + 1])];
            }
        }

        return [...$widths, 1, 1];
    }

    /** Whether $data is a GS1 payload this symbology has room for. */
    public static function accepts(string $data): bool
    {
        if (!ElementString::isParsable($data)) {
            return false;
        }

        $elements = ElementString::parse($data);

        if (!GeneralField::accepts($elements->payload())) {
            return false;
        }

        try {
            Encodation::values($elements);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }
}
