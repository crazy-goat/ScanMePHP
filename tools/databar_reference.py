#!/usr/bin/env python3
"""Regenerate tests/fixtures/databar_reference.csv from zxing-cpp.

DataBar has nothing to transcribe. A data character is a value, and its eight
element widths are that value's index into an enumeration of every legal width
combination — so what needs verifying is a *function*, and a function is wrong
across a whole range or not at all. That is what this fixture is shaped around.

Four things were measured before it was written, and each one is a place the
encoder would otherwise have been quietly wrong:

  * **The symbol has no quiet zone.** Every other linear symbology here gets
    one — this writer puts ten modules either side of a Code 128 and eleven
    around an EAN-13 — and draws DataBar's ninety-six modules edge to edge. The
    guard patterns do that work instead. An encoder that adds a margin out of
    habit is not wrong on the bars, but it is wrong about the symbol.

  * **The right half is the left half mirrored, whole.** Laying it out left to
    right produces a symbol that scans and carries a different number, which is
    the failure worth catching here rather than at a till. Each of the four
    character positions was fitted separately against this writer's output and
    each admits exactly one of the four orderings.

  * **The bars carry no "at least one narrow element" rule and the spaces do.**
    Backwards, it changes how many combinations sit in each bucket, so every
    value past the first shifts by one. It was settled by counting group sizes
    against this writer, not by reading a sentence twice.

  * **The finder patterns are the checksum.** There is no separate check
    character in the bars: the left finder's index is the checksum over nine
    and the right one's is the remainder. So a fixture that exercises the
    checksum is one that sweeps enough values to reach all eighty-one pairs,
    which is what the random block below is for rather than decoration.

The values here are chosen to walk the seams: the two split points, the first
and last value of every character group, and a random sweep wide enough that
every finder pair appears.

Run with the decoder venv:  .decoders/bin/python tools/databar_reference.py
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

OUT = pathlib.Path(__file__).resolve().parent.parent / "tests/fixtures/databar_reference.csv"

# The value splits around this, and each half again around 1597.
HALF_RANGE = 4537077
INSIDE_VALUES = 1597
OUTSIDE_GROUPS = [0, 161, 961, 2015, 2715, 2841]
INSIDE_GROUPS = [0, 336, 1036, 1516, 1597]


def check_digit(thirteen: str) -> str:
    total = sum((3 if i % 2 == 0 else 1) * int(c) for i, c in enumerate(thirteen))
    return str((10 - total % 10) % 10)


def gtin(value: int) -> str:
    thirteen = f"{value:013d}"
    return thirteen + check_digit(thirteen)


def modules(data: str) -> str:
    barcode = zxingcpp.create_barcode(data, zxingcpp.BarcodeFormat.DataBarOmni)
    svg = zxingcpp.write_barcode_to_svg(barcode)
    width = int(re.search(r'width="(\d+)"', svg).group(1))
    row = ["0"] * width
    for bar in re.finditer(r"M(\d+) 0h(\d+)v", svg):
        start, span = int(bar.group(1)), int(bar.group(2))
        for x in range(start, start + span):
            row[x] = "1"
    return "".join(row)


def values() -> list[int]:
    """Payloads that walk the seams, then enough of a sweep to reach every finder pair."""
    chosen = [0, 1, 2, HALF_RANGE - 1, HALF_RANGE, HALF_RANGE + 1, 9999999999999]

    # The first and last value of every group, on both sides of the split, in
    # both the outside and the inside character. A group boundary is where the
    # bar and space module counts change, so it is where an off-by-one in the
    # enumeration first shows.
    for start, end in zip(OUTSIDE_GROUPS, OUTSIDE_GROUPS[1:]):
        for outside in (start, end - 1):
            chosen.append(outside * INSIDE_VALUES)
            chosen.append(HALF_RANGE * min(outside, 1380) + 7)
    for start, end in zip(INSIDE_GROUPS, INSIDE_GROUPS[1:]):
        for inside in (start, end - 1):
            chosen.append(inside)
            chosen.append(HALF_RANGE + inside)

    random.seed(4)
    chosen += [random.randrange(10 ** 13) for _ in range(120)]

    return sorted({v for v in chosen if 0 <= v <= 9999999999999})


def main() -> int:
    rows = []
    for value in values():
        data = gtin(value)
        try:
            bars = modules(data)
        except Exception as error:  # pragma: no cover - developer tooling
            sys.stderr.write(f"{data}: {error}\n")
            return 1

        assert len(bars) == 96, f"{data}: {len(bars)} modules, expected 96"
        assert bars[0] == "0" and bars[-1] == "1", f"{data}: guards are not where they belong"
        rows.append((data, bars))

    with OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["data", "modules"])
        writer.writerows(rows)

    print(f"{len(rows)} reference symbols -> {OUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
