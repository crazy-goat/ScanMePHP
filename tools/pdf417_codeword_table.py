#!/usr/bin/env python3
"""Measure src/Encoding/Pdf417/CodewordPatterns.php from zxing-cpp's symbols.

Everything else about PDF417 in this library is derived. This table is not, and
the reason is worth writing down, because "we could not derive it" is a claim
that has to be tested rather than assumed.

A PDF417 codeword is seventeen modules: four bars and four spaces, alternating,
starting with a bar, each one to six modules wide. There are 10480 such
patterns. The cluster a pattern belongs to *is* derivable -- it is
(b1 - b2 + b3 - b4) mod 9 over the four bar widths, and only 0, 3 and 6 occur --
and that formula holds for all 2787 patterns in use, with no exceptions. But
the assignment of the 929 values to the 929 patterns within a cluster follows no
order at all. Sorting each cluster by the pattern read as a seventeen-bit
integer, ascending or descending, or by the width tuple, ascending or
descending, places at most two of several hundred known values on the right
pattern. The table is a designed table, not a computed one.

So it is measured, and the measurement is arranged so that trusting the oracle
is not what makes it right:

  * The seed comes from **row indicators**, whose values follow from the
    geometry by formulas this library states itself: for row r, with k = r / 3,
    the left indicator of a row 0 mod 3 is 30k + (rows - 1) / 3, and so on
    through the six cases. Sweeping payloads, error correction levels and column
    counts yields thousands of (cluster, value, pattern) triples this way. They
    came out with **zero contradictions**, which is the check on the formulas:
    one wrong case would have collided within a handful of symbols.

  * The rest is grown from the seed by a self-policing bootstrap. For each
    symbol the whole codeword stream is predicted from arithmetic written here
    -- the length descriptor, the mode latch, numeric or byte compaction, the
    pad codewords and the Reed-Solomon check codewords -- and the symbol is
    **only learnt from if every cell whose value is already known agrees**. A
    symbol that disagrees anywhere is discarded whole rather than partly
    believed. Without that rule one bad prediction poisons the table and the
    contradictions cascade; with it, roughly half the symbols are refused and
    the other half agree completely.

  * The refusals are themselves a result. Passing ``bytes`` makes this writer
    prepend an ECI header, codeword 927 followed by 899, to declare binary data;
    the byte compaction after it agrees with ours codeword for codeword. That is
    the ``bytes``-versus-``str`` trap in its precise form, and it is why the
    fixture's payloads are ``str``.

Text compaction is deliberately not implemented here. It is not needed to reach
every value -- byte compaction over random bytes covers them near-uniformly --
and duplicating the interesting half of the encoder in the measuring tool would
weaken the fixture that tests it.

What guards the result is not this script but PHP: Pdf417CodewordPatternsTest
re-derives the cluster of all 2787 entries, checks the table is a bijection in
each cluster, and checks every pattern is seventeen modules of eight elements
one to six wide starting with a bar. A corrupted entry fails those.

Run with the decoder venv:  .decoders/bin/python tools/pdf417_codeword_table.py
"""

import collections
import pathlib
import random
import sys

try:
    import zxingcpp
except ImportError:
    print("zxing-cpp is missing; run: composer decoders:install", file=sys.stderr)
    raise SystemExit(1)

PAD, BYTE_LATCH, NUMERIC_LATCH, BYTE_LATCH_6 = 900, 901, 902, 924
MODULUS, ROOT = 929, 3
START, STOP = 17, 18


def numeric_codewords(digits: str) -> list[int]:
    """Base-900 conversion of each group of up to 44 digits, with a guard 1."""
    out = []
    for i in range(0, len(digits), 44):
        number = int("1" + digits[i:i + 44])
        group = []
        while number:
            group.append(number % 900)
            number //= 900
        out += group[::-1]
    return out


def byte_codewords(data: bytes) -> list[int]:
    """Six bytes to five codewords; a tail shorter than six goes byte by byte."""
    out = []
    full = len(data) // 6 * 6
    for i in range(0, full, 6):
        number = int.from_bytes(data[i:i + 6], "big")
        group = []
        for _ in range(5):
            group.append(number % 900)
            number //= 900
        out += group[::-1]

    return out + list(data[full:])


def check_codewords(data: list[int], count: int) -> list[int]:
    generator = [1]
    for i in range(1, count + 1):
        root = pow(ROOT, i, MODULUS)
        nxt = [0] * (len(generator) + 1)
        for j, coefficient in enumerate(generator):
            nxt[j] = (nxt[j] + coefficient) % MODULUS
            nxt[j + 1] = (nxt[j + 1] - coefficient * root) % MODULUS
        generator = nxt

    remainder = [0] * count
    for word in data:
        factor = (word + remainder[0]) % MODULUS
        remainder = remainder[1:] + [0]
        for j in range(count):
            remainder[j] = (remainder[j] - factor * generator[j + 1]) % MODULUS

    return [(-coefficient) % MODULUS for coefficient in remainder]


def rows_of(payload, **options) -> list[str]:
    """The symbol's distinct module rows, one string of 0 and 1 each."""
    barcode = zxingcpp.create_barcode(payload, zxingcpp.BarcodeFormat.PDF417, **options)
    image = zxingcpp.write_barcode_to_image(barcode, scale=1, add_hrt=False, add_quiet_zones=False)
    view = memoryview(image)
    height, width = view.shape[0], view.shape[1]
    raw = bytearray(view)

    rows, previous = [], None
    for row in range(height):
        bits = "".join("1" if raw[row * width + col] < 128 else "0" for col in range(width))
        if bits != previous:
            rows.append(bits)
            previous = bits

    return rows


def widths(bits: str) -> tuple[int, ...]:
    out, run, current = [], 0, bits[0]
    for bit in bits:
        if bit == current:
            run += 1
        else:
            out.append(run)
            current = bit
            run = 1
    out.append(run)

    return tuple(out)


def cluster_of(pattern: tuple[int, ...]) -> int:
    bars = pattern[0::2]

    return (bars[0] - bars[1] + bars[2] - bars[3]) % 9


def seed_from_row_indicators(table: dict, rev: dict) -> None:
    """Values this library can state from the geometry, so not taken on trust."""
    random.seed(11)
    alphabet = "".join(chr(c) for c in range(32, 127))

    for trial in range(2500):
        length = random.randint(1, 900)
        text = "".join(random.choice(alphabet) for _ in range(length))
        options = {"ec_level": str(random.randint(0, 8))}
        if trial % 5 == 0:
            options["columns"] = str(random.randint(1, 30))
        try:
            rows = rows_of(text, **options)
        except Exception:
            continue

        columns = (len(rows[0]) - 1) // 17 - 4
        level, count = int(options["ec_level"]), len(rows)
        third, remainder = (count - 1) // 3, (count - 1) % 3

        for index, row in enumerate(rows):
            cluster, k = (index % 3) * 3, index // 3
            if index % 3 == 0:
                left, right = 30 * k + third, 30 * k + columns - 1
            elif index % 3 == 1:
                left, right = 30 * k + level * 3 + remainder, 30 * k + third
            else:
                left, right = 30 * k + columns - 1, 30 * k + level * 3 + remainder

            for value, start in ((left, START), (right, len(row) - STOP - 17)):
                pattern = widths(row[start:start + 17])
                assert cluster_of(pattern) == cluster, "the cluster formula does not hold"
                assert table.get((cluster, value), pattern) == pattern, \
                    f"row indicator contradiction at cluster {cluster} value {value}"
                table[(cluster, value)] = pattern
                rev[(cluster, pattern)] = value


def grow(table: dict, rev: dict) -> tuple[int, int]:
    """Predict whole symbols; learn only from those that agree everywhere."""
    random.seed(23)
    accepted = refused = 0

    for trial in range(6000):
        if trial % 2 == 0:
            length = random.randint(1, 360)
            payload = bytes(random.randrange(256) for _ in range(length))
            body = [BYTE_LATCH_6 if length % 6 == 0 else BYTE_LATCH] + byte_codewords(payload)
        else:
            digits = "".join(random.choice("0123456789") for _ in range(random.randint(1, 500)))
            digits = "1" + digits[1:] if digits[0] == "0" else digits
            payload = digits
            body = [NUMERIC_LATCH] + numeric_codewords(digits)

        options = {"ec_level": str(random.randint(0, 6))}
        if trial % 7 == 0:
            options["columns"] = str(random.randint(1, 30))
        try:
            rows = rows_of(payload, **options)
        except Exception:
            refused += 1
            continue

        columns = (len(rows[0]) - 1) // 17 - 4
        checks = 2 ** (int(options["ec_level"]) + 1)
        data = len(rows) * columns - checks
        stream = [data] + body
        if len(stream) > data:
            refused += 1
            continue
        stream += [PAD] * (data - len(stream))
        stream += check_codewords(stream, checks)

        learnt, agrees = [], True
        for index, value in enumerate(stream):
            row, column = divmod(index, columns)
            cluster = (row % 3) * 3
            start = START + (column + 1) * 17
            pattern = widths(rows[row][start:start + 17])
            if table.get((cluster, value), pattern) != pattern \
                    or rev.get((cluster, pattern), value) != value:
                agrees = False
                break
            learnt.append((cluster, value, pattern))

        if not agrees:
            refused += 1
            continue

        for cluster, value, pattern in learnt:
            table.setdefault((cluster, value), pattern)
            rev.setdefault((cluster, pattern), value)
        accepted += 1

    return accepted, refused


def as_modules(pattern: tuple[int, ...]) -> int:
    """The pattern as a seventeen-bit integer, first bar in the high bit."""
    bits, dark = 0, True
    for element in pattern:
        for _ in range(element):
            bits = (bits << 1) | (1 if dark else 0)
        dark = not dark

    return bits


def render(table: dict) -> str:
    lines = []
    for cluster in (0, 3, 6):
        lines.append(f"        {cluster} => [")
        row = []
        for value in range(929):
            row.append(f"0x{as_modules(table[(cluster, value)]):05X}")
            if len(row) == 8:
                lines.append("            " + ", ".join(row) + ",")
                row = []
        if row:
            lines.append("            " + ", ".join(row) + ",")
        lines.append("        ],")

    return "\n".join(lines)


HEADER = '''<?php

declare(strict_types=1);

namespace CrazyGoat\\ScanMePHP\\Encoding\\Pdf417;

/**
 * The seventeen-module pattern for every codeword value, in each of the three
 * clusters.
 *
 * The one table in this library that is measured rather than derived, and
 * `tools/pdf417_codeword_table.py` records both the measurement and the reason:
 * within a cluster, the assignment of the 929 values to the 929 patterns
 * follows no order that could be computed. Sorting by the pattern as an integer
 * or by its width tuple, either direction, puts at most two known values in the
 * right place out of several hundred.
 *
 * What *is* derived is which cluster a pattern belongs to. It is
 * (b1 - b2 + b3 - b4) mod 9 over the four bar widths, and only 0, 3 and 6
 * occur. {@see \\CrazyGoat\\ScanMePHP\\Tests\\Pdf417CodewordPatternsTest} re-derives
 * it for all 2787 entries, and checks the rest of what a codeword has to be:
 * seventeen modules, eight alternating elements, each one to six wide, starting
 * with a bar, and one pattern per value in each cluster. Those together are
 * strong enough that a corrupted entry cannot pass them.
 *
 * A row's cluster is its index modulo three, times three.
 *
 * @internal Generated by tools/pdf417_codeword_table.py; do not edit by hand.
 */
final class CodewordPatterns
{
    /** The number of distinct values a codeword can take, and the field size. */
    public const VALUES = 929;

    /** @var array<int, list<int>> Cluster to value to seventeen-bit pattern */
    private const PATTERNS = [
'''

FOOTER = '''    ];

    /** The seventeen modules of $value in $cluster, high bit first. */
    public static function pattern(int $cluster, int $value): int
    {
        return self::PATTERNS[$cluster][$value];
    }

    /** @return list<int> Every pattern in $cluster, indexed by value */
    public static function cluster(int $cluster): array
    {
        return self::PATTERNS[$cluster];
    }
}
'''


def main() -> int:
    table: dict = {}
    rev: dict = {}

    seed_from_row_indicators(table, rev)
    print(f"seeded {len(table)} entries from row indicators, no contradictions")

    accepted, refused = grow(table, rev)
    print(f"grew from {accepted} symbols that agreed everywhere; refused {refused}")

    per = collections.Counter(cluster for cluster, _ in table)
    for cluster in (0, 3, 6):
        assert per[cluster] == 929, f"cluster {cluster} has {per[cluster]} of 929 values"
    assert len(set(table.values())) == 3 * 929, "a pattern is used for two values"

    path = pathlib.Path(__file__).resolve().parent.parent / "src/Encoding/Pdf417/CodewordPatterns.php"
    path.write_text(HEADER + render(table) + FOOTER)
    print(f"wrote src/Encoding/Pdf417/{path.name}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
