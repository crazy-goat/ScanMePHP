<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataBar;

/**
 * The arithmetic every GS1 DataBar symbol is built from.
 *
 * DataBar does not have a pattern table. A data character is a *value*, and its
 * element widths are the value's index into an enumeration of every legal width
 * combination — so many bars summing to one fixed number, as many spaces
 * summing to another. {@see widths()} is that enumeration, walked one element
 * at a time: at each element it counts how many combinations start with a width
 * of one, of two, and so on, and subtracts until the remaining value falls
 * inside the current bucket. That is why there is no table here to be
 * transcribed wrongly — there is a function, and a fixture from an encoder we
 * did not write says it agrees.
 *
 * The function is the same for every member of the family. What differs is the
 * numbers fed to it, and those differ more than the family resemblance
 * suggests. Omnidirectional characters are four bars and four spaces; Limited
 * characters are seven of each. Omnidirectional puts the "at least one narrow
 * element" rule on the spaces; Limited puts it on the bars. Omnidirectional
 * varies its spaces fastest as the value counts up; Limited varies its bars.
 * None of those three could be guessed from the other symbology, and each was
 * measured against an oracle before it was written down.
 *
 * The checksum weights are not a table either: they are the powers of three
 * modulo the symbology's prime, in order. Reference implementations ship them
 * as dozens of literals, which is the same numbers spelled in the way most
 * likely to acquire a typo.
 *
 * @internal Shared by the DataBar generators.
 *
 * @phpstan-type CharacterTable array{elements: int, offsets: list<int>, combinations: list<int>, barModules: list<int>, spaceModules: list<int>, widestBar: list<int>, widestSpace: list<int>, narrowBar: bool, narrowSpace: bool, barsVaryFastest: bool}
 */
final class Patterns
{
    /** The prime the Omnidirectional checksum lives in. */
    public const OMNI_MODULUS = 79;

    /** The prime the Limited checksum lives in. */
    public const LIMITED_MODULUS = 89;

    /**
     * Omnidirectional's nine finder patterns, the one thing here that is a
     * table.
     *
     * They are not an enumeration of anything: the widths follow no rule that
     * generates all nine and nothing else, so they are measured from the
     * oracle's symbols and asserted against it. Each is five elements summing
     * to fifteen, and each ends in two single modules — which is what makes a
     * finder recognisable to a scanner sweeping a line at any angle.
     *
     * @var list<list<int>>
     */
    public const OMNI_FINDERS = [
        [3, 8, 2, 1, 1],
        [3, 5, 5, 1, 1],
        [3, 3, 7, 1, 1],
        [3, 1, 9, 1, 1],
        [2, 7, 4, 1, 1],
        [2, 5, 6, 1, 1],
        [2, 3, 8, 1, 1],
        [1, 5, 7, 1, 1],
        [1, 3, 9, 1, 1],
    ];

    /**
     * The outside characters' groups: 2841 values in five buckets.
     *
     * Read a column at a time. Group 0 spends twelve modules on its bars and
     * four on its spaces, no bar wider than eight and no space wider than one —
     * which leaves exactly one legal set of spaces, so the group holds as many
     * values as it has bar combinations. Group 4 is the mirror of that.
     *
     * @var CharacterTable
     */
    public const OUTSIDE = [
        'elements' => 4,
        'offsets' => [0, 161, 961, 2015, 2715],
        'combinations' => [1, 10, 34, 70, 126],
        'barModules' => [12, 10, 8, 6, 4],
        'spaceModules' => [4, 6, 8, 10, 12],
        'widestBar' => [8, 6, 4, 3, 1],
        'widestSpace' => [1, 3, 5, 6, 8],
        'narrowBar' => false,
        'narrowSpace' => true,
        'barsVaryFastest' => false,
    ];

    /**
     * The inside characters' groups: 1597 values in four buckets.
     *
     * @var CharacterTable
     */
    public const INSIDE = [
        'elements' => 4,
        'offsets' => [0, 336, 1036, 1516],
        'combinations' => [4, 20, 48, 81],
        'barModules' => [10, 8, 6, 4],
        'spaceModules' => [5, 7, 9, 11],
        'widestBar' => [7, 5, 3, 1],
        'widestSpace' => [2, 4, 6, 8],
        'narrowBar' => false,
        'narrowSpace' => true,
        'barsVaryFastest' => false,
    ];

    /** Values an outside character can carry. */
    public const OUTSIDE_VALUES = 2841;

    /** Values an inside character can carry. */
    public const INSIDE_VALUES = 1597;

    /**
     * The Limited characters' groups: 2013571 values in seven buckets.
     *
     * Nothing here lines up with the Omnidirectional tables above, which is the
     * point of writing it out separately rather than parameterising one table.
     * The characters are twice as long — seven bars and seven spaces over
     * twenty-six modules — the narrow-element rule is on the bars instead of
     * the spaces, and the seven groups are not sorted by anything: bar widths
     * run 9, 13, 17, 11, 15, 7, 19 as the value climbs. Every column was
     * recovered by watching where the oracle's own symbols change shape, not
     * read off a page.
     *
     * The one regularity worth knowing is that the two widest columns always
     * sum to nine, in every group. It is a useful check on a transcription and
     * it is not a rule anything here depends on.
     *
     * @var CharacterTable
     */
    public const LIMITED = [
        'elements' => 7,
        'offsets' => [0, 183064, 820064, 1000776, 1491021, 1979845, 1996939],
        'combinations' => [28, 728, 6454, 203, 2408, 1, 16632],
        'barModules' => [9, 13, 17, 11, 15, 7, 19],
        'spaceModules' => [17, 13, 9, 15, 11, 19, 7],
        'widestBar' => [3, 4, 6, 4, 5, 1, 8],
        'widestSpace' => [6, 5, 3, 5, 4, 8, 1],
        'narrowBar' => true,
        'narrowSpace' => false,
        'barsVaryFastest' => true,
    ];

    /** Values a Limited character can carry. */
    public const LIMITED_VALUES = 2013571;

    /**
     * Limited's eighty-nine finder patterns, as pairs of half-patterns.
     *
     * This is a table in the standard too, and a much larger one: eighty-nine
     * patterns of fourteen elements. It is stored here as pairs because that is
     * what it turned out to be. Every one of the eighty-nine splits into seven
     * spaces summing to nine and seven bars summing to nine, each of them a
     * composition of nine into seven parts of at most three whose last part is
     * one — and that set has exactly twenty-one members, which
     * {@see limitedFinderHalf()} enumerates with the same function everything
     * else here uses. So the table is two indices per finder rather than
     * fourteen widths, and the widths are generated rather than transcribed.
     *
     * What is *not* derivable is which pairs are used. Twenty-one halves make
     * 441 combinations and the standard uses eighty-nine of them, and the
     * selection follows no local rule — element widths, adjacent sums and
     * counts of wide elements all take the same values inside the chosen set as
     * outside it. So the pairs below are measured, one residue at a time, from
     * an encoder we did not write.
     *
     * @var list<array{int, int}>
     */
    public const LIMITED_FINDERS = [
        [0, 0], [0, 1], [0, 2], [0, 3], [0, 4], [0, 5], [0, 6], [0, 7], [0, 8],
        [0, 9], [0, 10], [0, 11], [0, 12], [0, 13], [0, 14], [0, 15], [0, 16],
        [0, 17], [0, 18], [0, 19], [0, 20], [1, 0], [1, 1], [1, 2], [1, 3],
        [1, 4], [1, 5], [1, 6], [1, 7], [1, 8], [1, 9], [1, 10], [1, 11],
        [1, 12], [1, 13], [1, 14], [1, 15], [1, 16], [1, 17], [1, 18], [1, 19],
        [1, 20], [2, 0], [2, 1], [2, 3], [2, 10], [2, 15], [3, 0], [3, 1],
        [3, 2], [3, 3], [3, 10], [3, 11], [3, 12], [3, 13], [3, 14], [3, 15],
        [3, 16], [3, 19], [6, 0], [6, 1], [6, 2], [6, 3], [6, 4], [6, 6],
        [6, 15], [6, 16], [6, 17], [6, 18], [6, 19], [6, 20], [10, 0], [10, 1],
        [10, 2], [10, 3], [10, 4], [10, 5], [10, 6], [10, 7], [10, 10],
        [15, 1], [15, 2], [15, 3], [15, 4], [15, 5], [15, 7], [15, 8],
        [15, 11], [16, 1],
    ];

    /** Half-patterns a Limited finder is built from. */
    public const LIMITED_FINDER_HALVES = 21;

    /** The prime the Expanded checksum lives in. */
    public const EXPANDED_MODULUS = 211;

    /**
     * Expanded's character groups: 4096 values in five buckets.
     *
     * Read the two rows as *positions*, not colours. An Expanded character is
     * four bars and four spaces summing to seventeen, and whether its first
     * element is a bar or a space depends on where the character sits in the
     * symbol — the whole symbol is one alternating run of elements, so the
     * phase falls out of the layout rather than the value. What the table fixes
     * is that the odd-numbered elements of a character sum to an even number,
     * and the 'bar' row here names those; 'space' names the even-numbered ones.
     * {@see character()} is therefore called with $spaceFirst false and the
     * result reversed for the right-hand character of a pair.
     *
     * The combinations row is Expanded's divisor, and the last group's is
     * larger than the group itself: 204 even combinations for 108 values, so
     * two thirds of that group's widths are never drawn. That is the standard's
     * arithmetic, not a rounding here — the value is split by 204 regardless.
     *
     * @var CharacterTable
     */
    public const EXPANDED = [
        'elements' => 4,
        'offsets' => [0, 348, 1388, 2948, 3988],
        'combinations' => [4, 20, 52, 104, 204],
        'barModules' => [12, 10, 8, 6, 4],
        'spaceModules' => [5, 7, 9, 11, 13],
        'widestBar' => [7, 5, 4, 3, 1],
        'widestSpace' => [2, 4, 5, 6, 8],
        'narrowBar' => true,
        'narrowSpace' => false,
        'barsVaryFastest' => false,
    ];

    /** Values an Expanded character carries: twelve bits, all of them used. */
    public const EXPANDED_VALUES = 4096;

    /**
     * Expanded's six finder patterns.
     *
     * Fifteen modules and five elements each, ending in two single modules like
     * every other finder in the family. Unlike Omnidirectional's, these are not
     * the checksum: which one goes where is decided by the number of characters
     * alone, and the checksum lives in a data character of its own.
     *
     * @var list<list<int>>
     */
    public const EXPANDED_FINDERS = [
        [1, 8, 4, 1, 1],
        [3, 6, 4, 1, 1],
        [3, 4, 6, 1, 1],
        [3, 2, 8, 1, 1],
        [2, 6, 5, 1, 1],
        [2, 2, 9, 1, 1],
    ];

    /**
     * Which finder goes in which pair, keyed by the number of pairs.
     *
     * A scanner reading one pair out of a stack has to know which pair it read,
     * and this sequence is how: no two pairs of a symbol share a finder unless
     * the symbol is long enough that the ambiguity is resolvable. The sequences
     * follow no rule that generates them — they are a table in the standard and
     * a table here, measured from the oracle one length at a time.
     *
     * @var array<int, list<int>>
     */
    public const EXPANDED_FINDER_SEQUENCES = [
        2 => [0, 0],
        3 => [0, 1, 1],
        4 => [0, 2, 1, 3],
        5 => [0, 4, 1, 3, 2],
        6 => [0, 4, 1, 3, 3, 5],
        7 => [0, 4, 1, 3, 4, 5, 5],
        8 => [0, 0, 1, 1, 2, 2, 3, 3],
        9 => [0, 0, 1, 1, 2, 2, 3, 4, 4],
        10 => [0, 0, 1, 1, 2, 2, 3, 4, 5, 5],
        11 => [0, 0, 1, 1, 2, 3, 3, 4, 4, 5, 5],
    ];

    /**
     * The checksum's weighting sequence, keyed by the number of data
     * characters.
     *
     * Each entry is a starting index: data character i weighs its widths by
     * 3^(8k), 3^(8k+1) ... 3^(8k+7) modulo 211, where k is the entry. The first
     * character always weighs by the powers from zero; after that the order is
     * scrambled, and it is scrambled differently for every length. Every value
     * here was solved for against the oracle rather than transcribed.
     *
     * @var array<int, list<int>>
     */
    public const EXPANDED_WEIGHTS = [
        3 => [0, 1, 2],
        4 => [0, 5, 6, 3],
        5 => [0, 5, 6, 3, 4],
        6 => [0, 9, 10, 3, 4, 13],
        7 => [0, 9, 10, 3, 4, 13, 14],
        8 => [0, 17, 18, 3, 4, 13, 14, 7],
        9 => [0, 17, 18, 3, 4, 13, 14, 7, 8],
        10 => [0, 17, 18, 3, 4, 13, 14, 11, 12, 21],
        11 => [0, 17, 18, 3, 4, 13, 14, 11, 12, 21, 22],
        12 => [0, 17, 18, 3, 4, 13, 14, 15, 16, 21, 22, 19],
        13 => [0, 17, 18, 3, 4, 13, 14, 15, 16, 21, 22, 19, 20],
        14 => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13],
        15 => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14],
        16 => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 17, 18, 15],
        17 => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 17, 18, 15, 16],
        18 => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 17, 18, 19, 20, 21],
        19 => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 17, 18, 19, 20, 21, 22],
        20 => [0, 1, 2, 3, 4, 5, 6, 7, 8, 13, 14, 11, 12, 17, 18, 15, 16, 21, 22, 19],
        21 => [0, 1, 2, 3, 4, 5, 6, 7, 8, 13, 14, 11, 12, 17, 18, 15, 16, 21, 22, 19, 20],
    ];

    /**
     * One data character's element widths, bars and spaces interleaved.
     *
     * $spaceFirst says which of the two the character opens with: an
     * Omnidirectional outside character opens with a bar, its inside character
     * and both Limited characters with a space.
     *
     * @param CharacterTable $table
     * @return list<int>
     */
    public static function character(int $value, array $table, bool $spaceFirst): array
    {
        $group = 0;
        foreach ($table['offsets'] as $i => $offset) {
            if ($value >= $offset) {
                $group = $i;
            }
        }

        $elements = $table['elements'];
        $remainder = $value - $table['offsets'][$group];

        $barModules = $table['barModules'][$group];
        $spaceModules = $table['spaceModules'][$group];
        $widestBar = $table['widestBar'][$group];
        $widestSpace = $table['widestSpace'][$group];

        $divisor = $table['combinations'][$group];
        if ($table['barsVaryFastest']) {
            $barValue = $remainder % $divisor;
            $spaceValue = intdiv($remainder, $divisor);
        } else {
            $spaceValue = $remainder % $divisor;
            $barValue = intdiv($remainder, $divisor);
        }

        $bars = self::widths($barValue, $barModules, $elements, $widestBar, $table['narrowBar']);
        $spaces = self::widths($spaceValue, $spaceModules, $elements, $widestSpace, $table['narrowSpace']);

        $widths = [];
        for ($i = 0; $i < $elements; $i++) {
            if ($spaceFirst) {
                $widths[] = $spaces[$i];
                $widths[] = $bars[$i];
            } else {
                $widths[] = $bars[$i];
                $widths[] = $spaces[$i];
            }
        }

        return $widths;
    }

    /**
     * The $value'th way to split $modules into $elements widths of at most
     * $widest.
     *
     * $requireNarrow drops every combination whose widths are all wider than
     * one module. Which side of a character carries that rule is a property of
     * the symbology, not of the enumeration.
     *
     * The walk counts by binomials and then corrects for the ceiling one
     * overwide element at a time. That correction is only exact while two
     * elements cannot both overflow at once, and where they can — the
     * Omnidirectional inside character's third and fourth groups — it counts
     * some combinations twice over. That is not a bug to fix here: this is the
     * enumeration the standard defines and every encoder implements, and
     * "correcting" it would silently produce a different symbol from every
     * scanner's idea of the same number. The group tables carry the count of
     * reachable combinations for exactly that reason, rather than deriving it.
     *
     * @return list<int>
     */
    public static function widths(
        int $value,
        int $modules,
        int $elements,
        int $widest,
        bool $requireNarrow,
    ): array {
        $widths = [];
        $remaining = $modules;
        $narrowSoFar = false;

        for ($element = 0; $element < $elements - 1; $element++) {
            $left = $elements - 1 - $element;
            $width = 1;
            $bucket = 0;

            while ($width <= $remaining) {
                $bucket = self::binomial($remaining - $width - 1, $left - 1);

                // The rule only bites while nothing chosen is a single module,
                // this element included: once one is, every combination still
                // ahead already satisfies it.
                if ($requireNarrow
                    && !$narrowSoFar
                    && $width > 1
                    && $remaining - $width - $left >= $left
                ) {
                    $bucket -= self::binomial($remaining - $width - $left - 1, $left - 1);
                }

                if ($left > 1) {
                    $overwide = 0;
                    for ($largest = $remaining - $width - $left + 1; $largest > $widest; $largest--) {
                        $overwide += self::binomial($remaining - $width - $largest - 1, $left - 2);
                    }
                    $bucket -= $overwide * $left;
                } elseif ($remaining - $width > $widest) {
                    $bucket--;
                }

                $value -= $bucket;
                if ($value < 0) {
                    break;
                }

                $width++;
            }

            $value += $bucket;
            $remaining -= $width;
            $widths[] = $width;
            $narrowSoFar = $narrowSoFar || $width === 1;
        }

        $widths[] = $remaining;

        return $widths;
    }

    /**
     * The checksum residue of a symbol's data character widths.
     *
     * The weights are the powers of three modulo $modulus rather than a literal
     * table, which is the same numbers with nothing to mistype.
     *
     * @param list<int> $widths every data character's widths, in value order
     */
    public static function checksum(array $widths, int $modulus): int
    {
        $checksum = 0;
        $weight = 1;
        foreach ($widths as $width) {
            $checksum = ($checksum + $width * $weight) % $modulus;
            $weight = $weight * 3 % $modulus;
        }

        return $checksum;
    }


    /**
     * The Expanded checksum residue of a symbol's data characters.
     *
     * Not the same shape as {@see checksum()}: the weights are still powers of
     * three, but which power a character starts from comes out of
     * {@see EXPANDED_WEIGHTS} rather than from its position, and the whole
     * check *character* is 211 x (characters - 3) plus this residue — a value
     * that reaches 4008 and so needs the twelve-bit character space that the
     * data itself never fills.
     *
     * @param list<list<int>> $characters every data character's widths, in order
     */
    public static function expandedChecksum(array $characters): int
    {
        $sequence = self::EXPANDED_WEIGHTS[\count($characters)]
            ?? throw new \InvalidArgumentException(sprintf(
                'DataBar Expanded has no weighting sequence for %d data characters',
                \count($characters)
            ));

        $checksum = 0;
        foreach ($characters as $index => $widths) {
            $weight = self::powerOfThree(8 * $sequence[$index], self::EXPANDED_MODULUS);
            foreach ($widths as $width) {
                $checksum = ($checksum + $width * $weight) % self::EXPANDED_MODULUS;
                $weight = $weight * 3 % self::EXPANDED_MODULUS;
            }
        }

        return $checksum;
    }

    private static function powerOfThree(int $exponent, int $modulus): int
    {
        $power = 1;
        for ($i = 0; $i < $exponent; $i++) {
            $power = $power * 3 % $modulus;
        }

        return $power;
    }

    /**
     * One of the twenty-one halves a Limited finder pattern is built from:
     * seven widths summing to nine, none wider than three, the last of them
     * one.
     *
     * @return list<int>
     */
    public static function limitedFinderHalf(int $index): array
    {
        return [...self::widths($index, 8, 6, 3, false), 1];
    }

    /**
     * The Limited finder pattern for a checksum residue.
     *
     * @return list<int>
     */
    public static function limitedFinder(int $checksum): array
    {
        [$spaceHalf, $barHalf] = self::LIMITED_FINDERS[$checksum];

        $spaces = self::limitedFinderHalf($spaceHalf);
        $bars = self::limitedFinderHalf($barHalf);

        $widths = [];
        for ($i = 0; $i < 7; $i++) {
            $widths[] = $spaces[$i];
            $widths[] = $bars[$i];
        }

        return $widths;
    }

    /**
     * @param list<int> $widths
     * @return list<int> the widths in reverse, for the mirrored half
     */
    public static function mirror(array $widths): array
    {
        return array_reverse($widths);
    }

    /**
     * Widths to a run-length string of light and dark modules.
     *
     * @param list<int> $widths
     */
    public static function modules(array $widths, bool $startsDark = false): string
    {
        $modules = '';
        $dark = $startsDark;
        foreach ($widths as $width) {
            $modules .= str_repeat($dark ? '1' : '0', $width);
            $dark = !$dark;
        }

        return $modules;
    }

    private static function binomial(int $n, int $r): int
    {
        if ($r < 0 || $n < 0 || $r > $n) {
            return 0;
        }

        $result = 1;
        for ($i = 0; $i < $r; $i++) {
            $result = intdiv($result * ($n - $i), $i + 1);
        }

        return $result;
    }
}
