<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataBarExpandedStacked\Backend;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\DataBar\Patterns;
use CrazyGoat\ScanMePHP\Generator\DataBarExpanded\Encodation\Characters;
use CrazyGoat\ScanMePHP\Generator\DataBarExpanded\Encodation\Encodation;
use CrazyGoat\ScanMePHP\Generator\DataBarExpanded\Encodation\GeneralField;
use CrazyGoat\ScanMePHP\Generator\DataBarExpandedStacked\DataBarExpandedStackedOptions;
use CrazyGoat\ScanMePHP\Generator\Gs1\ElementString;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * GS1 DataBar Expanded Stacked in pure PHP.
 *
 * The same data and the same characters as the linear symbol, cut into rows for
 * labels that have height rather than width. Nothing about the encodation
 * changes except one thing, and that one thing changes everything downstream:
 * a row may not end up holding a single character, so the payload sometimes
 * takes one more character of padding than the linear symbol would — which
 * shifts the character count, the variable length field and therefore the
 * checksum. That is why {@see Characters} is given the row width rather than
 * the layout being free to fold whatever it is handed.
 *
 * The layout itself is where the surprises are, and all three were measured
 * against an encoder we did not write because each draws a plausible symbol
 * when guessed wrongly:
 *
 *   * **Rows are cut at character boundaries, not at pair boundaries.** A row
 *     can end with a finder pattern and no character after it.
 *   * **Every second row is drawn mirrored** — right to left, so that a
 *     scanner sweeping back across the label reads the rows in order without
 *     lifting.
 *   * **A row holding exactly two characters is the exception**: it is drawn
 *     forwards even in a mirrored position, one module to the right.
 *
 * Between two rows sit three module rows of separator: the complement of the
 * row above, an alternating line, and the complement of the row below. The
 * complement alone would reproduce the finder patterns upside down, so within
 * a finder's columns the separator alternates instead — and it does so with a
 * running state carried in from the module before the finder, which is why two
 * finders in the same row can come out in opposite phases.
 */
final class PhpBackend implements BackendInterface
{
    /** Modules in a data character. */
    public const CHARACTER_MODULES = 17;

    /** Modules in a finder pattern. */
    public const FINDER_MODULES = 15;

    /** Modules in the two guard patterns together. */
    public const GUARD_MODULES = 4;

    /** Height of a data row, in modules. */
    public const ROW_HEIGHT = 34;

    /** Module rows of separator between two data rows. */
    public const SEPARATOR_ROWS = 3;

    /** Modules at each end of a separator that stay light. */
    private const SEPARATOR_MARGIN = 4;

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
        $options = $options instanceof DataBarExpandedStackedOptions
            ? $options
            : new DataBarExpandedStackedOptions();

        $elements = ElementString::parse($data);
        $perRow = 2 * $options->columns;
        $characters = Characters::of($elements, $perRow);

        $width = self::GUARD_MODULES
            + self::CHARACTER_MODULES * $perRow
            + self::FINDER_MODULES * $options->columns;

        $sequence = Patterns::EXPANDED_FINDER_SEQUENCES[intdiv(\count($characters->widths) + 1, 2)];

        $rows = [];
        foreach (array_chunk($characters->widths, $perRow) as $index => $chunk) {
            $rows[] = $this->row($chunk, $index, $index * $perRow, $sequence, $width, $perRow);
        }

        [$modules, $heights] = $this->stack($rows, $width);

        return new Symbol(
            width: $width,
            height: \count($heights),
            modules: $modules,
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            // None, as everywhere in this family: the guard patterns do a quiet
            // zone's work and the standard asks for no margin beyond them.
            quietZone: QuietZone::none(),
            rowHeights: $heights,
            text: $elements->humanReadable(),
            metadata: [
                'symbology' => Symbology::DataBarExpandedStacked->value,
                'characters' => \count($characters->widths),
                'rows' => \count($rows),
                'columns' => $options->columns,
                'checksum' => $characters->checksum,
                'checkCharacter' => $characters->checkCharacter,
            ],
        );
    }

    /**
     * One data row and the separator that belongs to it, both padded to the
     * symbol's width.
     *
     * @param list<list<int>> $chunk this row's characters
     * @param int $first the first character's index in the whole symbol
     * @param list<int> $sequence the finder pattern for each pair
     * @return array{string, string}
     */
    private function row(array $chunk, int $index, int $first, array $sequence, int $width, int $perRow): array
    {
        $widths = [1, 1];
        $finders = [];
        $position = 2;

        foreach ($chunk as $offset => $character) {
            $global = $first + $offset;

            // The right-hand character of a pair is drawn backwards, exactly as
            // in the linear symbol.
            $element = $global % 2 === 1 ? Patterns::mirror($character) : $character;
            $widths = [...$widths, ...$element];
            $position += array_sum($element);

            if ($global % 2 !== 0) {
                continue;
            }

            $pair = intdiv($global, 2);
            $finder = Patterns::EXPANDED_FINDERS[$sequence[$pair]];

            // Which way round a finder is drawn follows the alternating run of
            // elements, and a row starts that run afresh — so it is the finder's
            // place in the row that decides, not its place in the symbol. The
            // two agree for every even column count, which is why this only
            // shows up at one or three pairs per row.
            $inRow = intdiv($offset, 2);
            $widths = [...$widths, ...($inRow % 2 === 1 ? Patterns::mirror($finder) : $finder)];
            $finders[] = [$position, $position + self::FINDER_MODULES];
            $position += self::FINDER_MODULES;
        }

        $widths[] = 1;
        $widths[] = 1;

        $content = Patterns::modules($widths);
        $separator = $this->separator($content, $finders);

        // Every second row reads right to left. A short last row of exactly two
        // characters is the one that does not, and it sits a module further
        // right instead.
        $twoCharacters = \count($chunk) === 2 && \count($chunk) < $perRow;
        if ($index % 2 === 1 && !$twoCharacters) {
            $content = strrev($content);
            $separator = strrev($separator);
        }

        $offset = $index % 2 === 1 && $twoCharacters ? 1 : 0;
        $pad = static fn (string $row): string => str_repeat('0', $offset)
            . $row
            . str_repeat('0', $width - $offset - \strlen($row));

        return [$pad($content), $pad($separator)];
    }

    /**
     * The separator line that sits against one data row.
     *
     * The complement of the row, except inside a finder pattern's columns:
     * there it alternates, so that the separator does not print the finder
     * again in reverse. The alternation carries a running state in from the
     * module before the finder rather than starting fresh, which is why the
     * same finder pattern can come out in either phase.
     *
     * @param list<array{int, int}> $finders each finder's column range
     */
    private function separator(string $content, array $finders): string
    {
        $length = \strlen($content);
        $separator = [];
        for ($i = 0; $i < $length; $i++) {
            $separator[] = $content[$i] === '0' ? '1' : '0';
        }

        foreach ($finders as [$start, $end]) {
            $dark = $start > 0 && $separator[$start - 1] === '1';

            for ($i = $start; $i < $end; $i++) {
                if ($content[$i] === '1' || $dark) {
                    $separator[$i] = '0';
                    $dark = false;

                    continue;
                }

                $separator[$i] = '1';
                $dark = true;
            }
        }

        for ($i = 0; $i < self::SEPARATOR_MARGIN; $i++) {
            $separator[$i] = '0';
            $separator[$length - 1 - $i] = '0';
        }

        return implode('', $separator);
    }

    /**
     * The rows and separators as one module grid, with the height of each.
     *
     * @param list<array{string, string}> $rows
     * @return array{string, list<int>}
     */
    private function stack(array $rows, int $width): array
    {
        $middle = '';
        for ($i = 0; $i < $width; $i++) {
            $middle .= $i % 2 === 1
                && $i >= self::SEPARATOR_MARGIN
                && $i <= $width - 1 - self::SEPARATOR_MARGIN ? '1' : '0';
        }

        $modules = '';
        $heights = [];

        foreach ($rows as $index => [$content, $separator]) {
            if ($index > 0) {
                $modules .= $rows[$index - 1][1] . $middle . $separator;
                $heights = [...$heights, 1, 1, 1];
            }

            $modules .= $content;
            $heights[] = self::ROW_HEIGHT;
        }

        return [$modules, $heights];
    }

    /** Whether $data is a GS1 payload this symbology has room for. */
    public static function accepts(string $data, ?DataBarExpandedStackedOptions $options = null): bool
    {
        if (!ElementString::isParsable($data)) {
            return false;
        }

        $elements = ElementString::parse($data);

        if (!GeneralField::accepts($elements->payload())) {
            return false;
        }

        try {
            Encodation::values($elements, 2 * ($options ?? new DataBarExpandedStackedOptions())->columns);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }
}
