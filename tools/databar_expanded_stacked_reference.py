#!/usr/bin/env python3
"""Regenerate tests/fixtures/databar_expanded_stacked_reference.csv from zxing-cpp.

The stacked symbol carries the same data and the same characters as the linear
one, so what this fixture is for is the folding, and the folding is where the
surprises are. Each of these was measured against this writer, and each draws a
plausible symbol when guessed wrongly:

  * **A row may not be left holding a single character.** When the last one
    would be, the payload takes one more character of padding — which moves the
    character count, the variable length bits and therefore the checksum. So it
    has to be decided before the bit stream is written, not while folding.

  * **Rows are cut at character boundaries, not at pair boundaries**, so a row
    can end with a finder pattern and no character after it.

  * **Every second row is drawn mirrored**, right to left, so a scanner sweeping
    back across the label reads the rows in order.

  * **A row of exactly two characters is the exception**: it is drawn forwards
    even in a mirrored position, one module to the right. A three-character row
    in the same position is mirrored like any other, which is what says this is
    about the two-character row and not about the last row.

  * **The separator is three module rows**: the complement of the row above, an
    alternating line, and the complement of the row below. Inside a finder
    pattern's columns the complement would print the finder again upside down,
    so there the separator alternates — carrying a running state in from the
    module before the finder, which is why two finders in one row can come out
    in opposite phases. Four modules at each end stay light.

  * **A data row is 34 modules tall and a separator row is one.**

Only two character pairs per row are covered here, because that is the only
width this writer draws. The wider foldings are checked by reading them back
instead; see DecoderRoundTripTest.

Run with the decoder venv:
  .decoders/bin/python tools/databar_expanded_stacked_reference.py
"""

import csv
import pathlib
import random
import re
import sys

try:
    import zxingcpp
except ImportError:
    print("zxing-cpp is missing; run: composer decoders:install", file=sys.stderr)
    raise SystemExit(1)

OUT = pathlib.Path(__file__).resolve().parent.parent / "tests/fixtures/databar_expanded_stacked_reference.csv"

ROW_HEIGHT = 34
ALPHA = "ABCDEFGHIJKLMNOPQRSTUVWXYZ"
LOWER = ALPHA.lower()
DIGITS = "0123456789"
PUNCTUATION = "!\"%&'*+,-./:;<=>?_ "


def rows(data: str) -> list[str]:
    """The symbol's distinct module rows, top to bottom."""
    barcode = zxingcpp.create_barcode(data, zxingcpp.BarcodeFormat.DataBarExpStk)
    svg = zxingcpp.write_barcode_to_svg(barcode)
    width, height = (int(x) for x in re.search(r'width="(\d+)" height="(\d+)"', svg).groups())

    grid = [["0"] * width for _ in range(height)]
    for bar in re.finditer(r"M(\d+) (\d+)h(\d+)v(\d+)", svg):
        x, y, dx, dy = (int(g) for g in bar.groups())
        for row in range(y, y + dy):
            for column in range(x, x + dx):
                grid[row][column] = "1"

    out = []
    heights = []
    for row in grid:
        line = "".join(row)
        if out and out[-1] == line:
            heights[-1] += 1
        else:
            out.append(line)
            heights.append(1)

    # A data row is 34 modules tall and a separator row one, and nothing else
    # occurs; a fixture row that broke that would mean the reading is wrong.
    assert all(h in (1, ROW_HEIGHT) for h in heights), f"{data}: row heights {heights}"

    return out


def check_digit(thirteen: str) -> str:
    total = sum((3 if i % 2 == 0 else 1) * int(c) for i, c in enumerate(thirteen))
    return str((10 - total % 10) % 10)


def gtin(value: int) -> str:
    thirteen = f"{value:013d}"
    return thirteen + check_digit(thirteen)


def payloads() -> list[str]:
    chosen = []

    # Every row count, and with it every shape the last row can take: four
    # characters, three ending on a finder, and two.
    for length in range(1, 31):
        chosen.append("(90)" + "1" * length)
        chosen.append("(90)" + (ALPHA * 2)[:length])
        chosen.append("(90)" + (LOWER * 2)[:length])
    for length in range(1, 27):
        chosen.append("(90)" + "1" * 30 + "(91)" + "1" * length)

    # Both encodation methods, and the padding character the row rule adds.
    for value in (0, 1, 999999999999, 123456789012):
        chosen.append("(01)" + gtin(value))
    chosen.append("(01)09501101020917(10)LOT0001")
    chosen.append("(01)09501101020917(21)SERIAL(11)991201")
    chosen.append("(90)" + PUNCTUATION)

    random.seed(31)
    pools = [DIGITS, ALPHA, LOWER, ALPHA + DIGITS, ALPHA + LOWER + DIGITS + PUNCTUATION]
    for _ in range(70):
        parts = []
        if random.random() < 0.4:
            parts.append("(01)" + gtin(random.randrange(10**13)))
        for ai in random.sample(["90", "91", "10", "21"], random.randint(1, 2)):
            pool = random.choice(pools)
            parts.append("(%s)%s" % (ai, "".join(random.choice(pool) for _ in range(random.randint(1, 20)))))
        chosen.append("".join(parts))

    return sorted(set(chosen))


def main() -> int:
    out = []
    for data in payloads():
        try:
            out.append((data, "|".join(rows(data))))
        except Exception as error:  # a payload this writer refuses is not a fixture
            print(f"skipped {data}: {error}", file=sys.stderr)

    out.sort()

    with OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["data", "rows"])
        writer.writerows(out)

    shapes = {len(m.split("|")) for _, m in out}
    print(f"{len(out)} reference symbols, {len(shapes)} distinct row counts -> {OUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
