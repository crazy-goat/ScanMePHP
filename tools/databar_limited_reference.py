#!/usr/bin/env python3
"""Regenerate tests/fixtures/databar_limited_reference.csv from zxing-cpp.

Limited shares an arithmetic with DataBar Omnidirectional and shares none of
its numbers, so this fixture exists to hold the differences down. Everything it
is built on was measured against this writer rather than carried over:

  * **The characters are seven bars and seven spaces, not four and four**, and
    the "at least one narrow element" rule sits on the bars where
    Omnidirectional puts it on the spaces. Backwards, every value past the
    first shifts.

  * **The value climbs through the bars fastest.** Omnidirectional counts up
    through its spaces. Nothing announces which; two adjacent values do.

  * **The seven character groups are not sorted by anything.** Their bar widths
    run 9, 13, 17, 11, 15, 7, 19 as the value climbs, and their boundaries —
    183064, 820064, 1000776, 1491021, 1979845, 1996939 — were found by binary
    search on where this writer's symbols change shape.

  * **The finder is the checksum, and there are eighty-nine of them.** One
    finder, one residue each, nothing skipped. So a fixture that exercises the
    checksum is one that reaches all eighty-nine patterns, and the block below
    hunts for them by watching the finder this writer draws rather than by
    computing one.

  * **The symbol is 74 modules and the writer draws 79.** Five blank modules
    follow the right guard and none precede the left one, because the left
    guard is itself a space. The five are stripped here and asserted, so the
    fixture holds the symbol and the quiet zone is the encoder's business.

Run with the decoder venv:  .decoders/bin/python tools/databar_limited_reference.py
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

OUT = pathlib.Path(__file__).resolve().parent.parent / "tests/fixtures/databar_limited_reference.csv"

# Each of the two characters carries a value below this; the pair of them is
# the whole thirteen digits, which is why Limited stops short of 2 x 10^12.
HALF_RANGE = 2013571
MAX_VALUE = 1999999999999
GROUPS = [0, 183064, 820064, 1000776, 1491021, 1979845, 1996939, HALF_RANGE]

MODULES = 74
RIGHT_QUIET_ZONE = 5
FINDERS = 89


def check_digit(thirteen: str) -> str:
    total = sum((3 if i % 2 == 0 else 1) * int(c) for i, c in enumerate(thirteen))
    return str((10 - total % 10) % 10)


def gtin(value: int) -> str:
    thirteen = f"{value:013d}"
    return thirteen + check_digit(thirteen)


def modules(data: str) -> str:
    barcode = zxingcpp.create_barcode(data, zxingcpp.BarcodeFormat.DataBarLtd)
    svg = zxingcpp.write_barcode_to_svg(barcode)
    width = int(re.search(r'width="(\d+)"', svg).group(1))
    row = ["0"] * width
    for bar in re.finditer(r"M(\d+) 0h(\d+)v", svg):
        start, span = int(bar.group(1)), int(bar.group(2))
        for x in range(start, start + span):
            row[x] = "1"

    drawn = "".join(row)
    assert len(drawn) == MODULES + RIGHT_QUIET_ZONE, f"{data}: {len(drawn)} modules drawn"
    assert drawn[MODULES:] == "0" * RIGHT_QUIET_ZONE, f"{data}: the right margin is not blank"
    return drawn[:MODULES]


def finder(bars: str) -> str:
    """The middle 18 modules — what the checksum chose."""
    return bars[28:46]


def values() -> list[int]:
    """Payloads that walk the seams, then whatever it takes to see every finder."""
    chosen = [0, 1, 2, HALF_RANGE - 1, HALF_RANGE, HALF_RANGE + 1, MAX_VALUE]

    # The first and last value of every character group, in both characters. A
    # group boundary is where the bar and space module counts change, so it is
    # where an off-by-one in the enumeration first shows.
    for start, end in zip(GROUPS, GROUPS[1:]):
        for value in (start, end - 1):
            chosen.append(value)
            chosen.append(value * HALF_RANGE)

    random.seed(4)
    chosen += [random.randrange(MAX_VALUE + 1) for _ in range(60)]

    return sorted({v for v in chosen if 0 <= v <= MAX_VALUE})


def main() -> int:
    rows = []
    seen = set()

    for value in values():
        data = gtin(value)
        bars = modules(data)
        seen.add(finder(bars))
        rows.append((data, bars))

    # Every residue draws a different finder and there are eighty-nine of them,
    # so this is the cheapest honest way to reach the whole checksum: keep
    # drawing until no pattern is missing.
    random.seed(21)
    while len(seen) < FINDERS:
        value = random.randrange(MAX_VALUE + 1)
        data = gtin(value)
        bars = modules(data)
        if finder(bars) in seen:
            continue
        seen.add(finder(bars))
        rows.append((data, bars))

    rows.sort()

    with OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["data", "modules"])
        writer.writerows(rows)

    print(f"{len(rows)} reference symbols, {len(seen)} finder patterns -> {OUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
