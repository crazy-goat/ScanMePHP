#!/usr/bin/env python3
"""Regenerate tests/fixtures/itf_reference.csv from zxing-cpp.

Interleaved 2 of 5 has one table of ten five-element patterns, and every one of
them is read twice: as bars when its digit is first of a pair, as spaces when
it is second. A swapped row is therefore wrong in two places at once and still
produces a scannable symbol — a different number, on a case of goods.

So the fixture is exhaustive over all one hundred digit pairs, which proves
both roles of every row rather than sampling them, plus longer payloads where a
misplaced pair boundary would show.

ITF-14 rows are stored as three '|'-separated module rows: the bearer bar
above, the bars inside the frame, and the bearer bar below. The frame is
structural — ITF is not self-checking, and it is what stops a beam that clips a
guard from reading a valid shorter number — so it belongs in the comparison and
not in a rendering note.

The two symbologies are read out of the writer differently, and the asymmetry
is the point. A plain ITF is captured without quiet zones, because its quiet
zone is a margin the renderer adds. An ITF-14 is captured *with* them, because
there the 10X quiet zone sits between the bars and the bearer bar, inside the
symbol: ask this writer for an ITF-14 with no quiet zones and it hands back a
frame flush against the bars, which is not a symbol any scanner will read.

Run with the decoder venv:  .decoders/bin/python tools/itf_reference.py
"""

import csv
import pathlib
import sys

try:
    import zxingcpp
except ImportError:  # pragma: no cover - developer tooling
    sys.exit("zxing-cpp is missing; run: composer decoders:install")

OUT = pathlib.Path(__file__).resolve().parent.parent / "tests/fixtures/itf_reference.csv"

CASES = []

# All one hundred pairs: the table in both of its roles, exhaustively.
for first in range(10):
    for second in range(10):
        CASES.append(("itf", f"{first}{second}"))

# Longer payloads. A single pair cannot show a pair boundary drawn in the wrong
# place, because there is only one.
for payload in (
    "1234",
    "123456",
    "1234567890",
    "0000000000000000",
    "9999999999999999",
    "1234567890123456789012",
    "0101010101",
    "9090909090",
    # An even count with a leading zero, which an encoder that pads odd inputs
    # would produce for the odd payload one digit shorter. We refuse to pad, so
    # this has to be a payload a caller wrote out in full.
    "0123",
) :
    CASES.append(("itf", payload))


def check_digit(payload: str) -> int:
    total = 0
    last = len(payload) - 1
    for index, digit in enumerate(payload):
        total += int(digit) * (3 if (last - index) % 2 == 0 else 1)
    return (10 - total % 10) % 10


# ITF-14: real GTIN-14 shapes, plus the edges.
for payload in (
    "1234567890123",
    "0000000000000",
    "9999999999999",
    "1000000000000",
    "0012345678901",
):
    CASES.append(("itf14", payload + str(check_digit(payload))))

FORMATS = {"itf": zxingcpp.ITF, "itf14": zxingcpp.ITF14}

assert len(CASES) == len(set(CASES)), "duplicate case: the fixture keys on the payload"


def rows(text: str, fmt, quiet_zones: bool) -> list[str]:
    """The distinct module rows of a symbol, top to bottom."""
    barcode = zxingcpp.create_barcode(text, fmt)
    image = zxingcpp.write_barcode_to_image(barcode, scale=1, add_hrt=False, add_quiet_zones=quiet_zones)
    view = memoryview(image)
    height, width = view.shape[0], view.shape[1]
    data = bytearray(view)

    distinct = []
    for y in range(height):
        row = "".join("1" if data[y * width + x] < 128 else "0" for x in range(width))
        if not distinct or distinct[-1] != row:
            distinct.append(row)

    return distinct


def main() -> int:
    out = []
    for symbology, data in CASES:
        try:
            distinct = rows(data, FORMATS[symbology], quiet_zones=symbology == "itf14")

            if symbology == "itf":
                assert len(distinct) == 1, f"{data}: ITF is one row, got {len(distinct)}"
                assert distinct[0].startswith("1010"), f"{data}: no start guard"
                bars = distinct[0]
            else:
                # Solid, then the bars inside a quiet zone inside the frame,
                # then solid. Anything else and the frame is not the shape this
                # fixture claims it is.
                assert len(distinct) == 3, f"{data}: expected three rows, got {len(distinct)}"
                assert set(distinct[0]) == {"1"}, f"{data}: top bearer is not solid"
                assert distinct[0] == distinct[2], f"{data}: bearer rows differ"

                middle = distinct[1]
                assert middle.startswith("11111" + "0" * 10), f"{data}: no bearer then quiet zone"
                assert middle.endswith("0" * 10 + "11111"), f"{data}: no quiet zone then bearer"
                assert middle[15:19] == "1010", f"{data}: no start guard after the quiet zone"

                bars = "|".join(distinct)

            out.append((symbology, data, bars))
        except Exception as error:  # pragma: no cover - developer tooling
            sys.stderr.write(f"{symbology} {data}: {error}\n")
            return 1

    with OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["symbology", "data", "modules"])
        writer.writerows(out)

    print(f"{len(out)} reference symbols -> {OUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
