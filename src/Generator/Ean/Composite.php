<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Ean;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;
use CrazyGoat\ScanMePHP\TextPlacement;
use CrazyGoat\ScanMePHP\TextRegion;

/**
 * A retail symbol with its add-on printed beside it, as one symbol.
 *
 * Generating the two halves is not the hard part — that has worked since the
 * add-ons landed. Placing them is, because an add-on is not simply concatenated:
 * the standard puts a gap between the two, draws the add-on's bars shorter than
 * the main symbol's, and prints the add-on's digits *above* its bars, since the
 * line below is already occupied by the main symbol's own.
 *
 * The result is an ordinary Symbol, so every renderer draws it with no special
 * case:
 *
 *     $composite = Composite::of(
 *         $scanme->generate('9788375780642', Symbology::Ean13),
 *         $scanme->generate('51299', Symbology::Ean5),
 *     );
 *     echo $scanme->renderSymbol($composite, Format::Png);
 *
 * One deviation from the printed standard is worth stating. There, the add-on's
 * digits sit level with the top of the main symbol's bars, inside the symbol's
 * own box. Here they are drawn above the box, in the band a renderer reserves
 * for text, and the add-on's bars are shortened by the same amount — so the
 * proportions match and the digits sit a little higher than a printed label
 * would put them. Drawing text inside the module grid is the only thing that
 * would close the gap, and it is not worth making every renderer able to do
 * for a difference of a few modules.
 */
final class Composite
{
    /**
     * Modules between the two symbols.
     *
     * ISO/IEC 15420 asks for at least seven. The add-on's own guard opens with
     * a space, so seven here leaves eight — and the main symbol's right quiet
     * zone is not part of this: it is the margin outside the pair, not the gap
     * within it.
     */
    public const SEPARATION = 7;

    /**
     * Module rows given over to the add-on's digits, taken off the top of its
     * bars.
     *
     * Small on purpose: the text a renderer draws is a couple of modules tall
     * against bars of sixty-four, and an add-on whose bars were dramatically
     * shorter would look wrong rather than standard.
     */
    public const ADDON_TEXT_ROWS = 4;

    /**
     * The symbologies an add-on may be printed beside.
     *
     * EAN-8 is deliberately not among them. Its bars accept an add-on happily
     * and a scanner reads the pair — we checked — but GS1 defines no add-on for
     * EAN-8, so the result is a label a retail system is entitled to reject. A
     * symbol that scans and is not valid is the failure mode this library
     * refuses everywhere else, and there is no reason to make an exception for
     * the one case where the mistake reaches a till.
     */
    private const MAIN = [
        Symbology::Ean13->value,
        Symbology::UpcA->value,
        Symbology::UpcE->value,
    ];

    /** And the two that are add-ons. */
    private const ADDON = [
        Symbology::Ean2->value,
        Symbology::Ean5->value,
    ];

    /**
     * @throws \InvalidArgumentException when either symbol is not what it is
     *         being used as
     */
    public static function of(Symbol $main, Symbol $addOn): Symbol
    {
        self::require($main, self::MAIN, 'main symbol', 'an EAN-13, UPC-A or UPC-E');
        self::require($addOn, self::ADDON, 'add-on', 'an EAN-2 or EAN-5');

        $mainBars = $main->rows()[0];
        $addOnBars = $addOn->rows()[0];
        $gap = str_repeat('0', self::SEPARATION);

        $addOnStart = \strlen($mainBars) + self::SEPARATION;
        $width = $addOnStart + \strlen($addOnBars);

        // The main symbol's own rows, with the add-on's columns extended to
        // match: a band at the top where only the main symbol has bars, the
        // band where both do, and the main symbol's guard descenders, which the
        // add-on has none of.
        $addOnBlank = str_repeat('0', \strlen($addOnBars));
        $rows = [
            $mainBars . $gap . $addOnBlank,
            $mainBars . $gap . $addOnBars,
        ];
        $rowHeights = [
            self::ADDON_TEXT_ROWS,
            $main->getRowHeights()[0] - self::ADDON_TEXT_ROWS,
        ];

        foreach (\array_slice($main->rows(), 1) as $index => $row) {
            $rows[] = $row . $gap . $addOnBlank;
            $rowHeights[] = $main->getRowHeights()[$index + 1];
        }

        return new Symbol(
            width: $width,
            height: \count($rows),
            modules: implode('', $rows),
            dimension: Dimension::Linear,
            quietZone: new QuietZone(
                left: $main->getQuietZone()->left,
                // The add-on's own right margin, which is narrower than a main
                // symbol's: five modules, not seven.
                right: $addOn->getQuietZone()->right,
            ),
            rowHeights: $rowHeights,
            textRegions: array_values(array_filter([
                self::region($main->getText(), TextPlacement::Below, 0, \strlen($mainBars)),
                self::region($addOn->getText(), TextPlacement::Above, $addOnStart, \strlen($addOnBars)),
            ])),
            metadata: [
                'symbology' => (string) $main->getMetadataValue('symbology'),
                'addOn' => (string) $addOn->getMetadataValue('symbology'),
                'main' => (string) $main->getText(),
                'addOnText' => (string) $addOn->getText(),
                'separation' => self::SEPARATION,
            ],
        );
    }

    private static function region(
        ?string $text,
        TextPlacement $placement,
        int $x,
        int $width
    ): ?TextRegion {
        return $text === null || $text === '' ? null : new TextRegion($text, $placement, $x, $width);
    }

    /**
     * @param list<string> $allowed
     *
     * @throws \InvalidArgumentException
     */
    private static function require(Symbol $symbol, array $allowed, string $role, string $expected): void
    {
        $symbology = $symbol->getMetadataValue('symbology');

        if (!\is_string($symbology) || !\in_array($symbology, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf(
                'The %s of a composite must be %s, got %s',
                $role,
                $expected,
                \is_string($symbology) ? $symbology : 'a symbol that does not say what it is'
            ));
        }
    }
}
