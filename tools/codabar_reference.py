#!/usr/bin/env python3
"""Regenerate tests/fixtures/codabar_reference.csv from zxing-cpp.

Codabar is one table of twenty seven-element patterns, and it is the least
regular table in this library: unlike every other two-width symbology here the
count of wide elements is not constant — two for digits, '-' and '$', three for
':', '/', '.', '+' and all four delimiters — so characters are not all the same
width and a swapped row changes the symbol's length as well as its meaning.
Which is to say it still scans, as different data of a different size.

So the fixture is exhaustive twice over: every one of the sixteen data
characters on its own, and every one of the sixteen delimiter pairs. Plus
longer payloads, where a misplaced inter-character gap would show.

The payload column holds the delimited character sequence a scanner reports,
because that is what this writer takes. Our generator takes the data alone and
gets its delimiters from options, so the test splits this back apart — which is
also a check that the split and the join agree.

One quirk is corrected here. This writer ends a Codabar symbol with a narrow
space, and a Codabar symbol ends on a bar: every character does, and there is
no stop pattern beyond the closing delimiter. The trailing space is quiet zone
drawn as if it were data. It is stripped, under an assertion that there is
exactly one of it.

Run with the decoder venv:  .decoders/bin/python tools/codabar_reference.py
"""

import csv
import pathlib
import sys

try:
    import zxingcpp
except ImportError:  # pragma: no cover - developer tooling
    sys.exit("zxing-cpp is missing; run: composer decoders:install")

OUT = pathlib.Path(__file__).resolve().parent.parent / "tests/fixtures/codabar_reference.csv"

CHARACTERS = "0123456789-$:/.+"
DELIMITERS = "ABCD"

CASES = []

# Every data character on its own, between the default delimiters.
for character in CHARACTERS:
    CASES.append(f"A{character}A")

# Every delimiter pair. They carry no data, so a wrong pattern for one of them
# is invisible in any symbol that does not use it. Two digits of payload rather
# than one, only so these sixteen do not collide with the sixteen above.
for start in DELIMITERS:
    for stop in DELIMITERS:
        CASES.append(f"{start}42{stop}")

for payload in (
    "123",
    "1234567890",
    "-$:/.+",
    "0123456789-$:/.+",
    "1-2$3:4/5.6+7",
    "00000000",
    "99999999",
    "4917234",
):
    CASES.append(f"A{payload}A")

assert len(CASES) == len(set(CASES)), "duplicate case: the fixture keys on the sequence"


def modules(text: str) -> str:
    barcode = zxingcpp.create_barcode(text, zxingcpp.Codabar)
    image = zxingcpp.write_barcode_to_image(barcode, scale=1, add_hrt=False, add_quiet_zones=False)
    view = memoryview(image)
    row = bytearray(view)[: view.shape[1]]
    bars = "".join("1" if pixel < 128 else "0" for pixel in row)

    assert bars.startswith("1"), f"{text}: does not open on a bar"
    assert bars.endswith("10"), f"{text}: expected exactly one trailing narrow space"

    return bars[:-1]


def main() -> int:
    rows = []
    for characters in CASES:
        try:
            rows.append((characters, modules(characters)))
        except Exception as error:  # pragma: no cover - developer tooling
            sys.stderr.write(f"{characters}: {error}\n")
            return 1

    with OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["characters", "modules"])
        writer.writerows(rows)

    print(f"{len(rows)} reference symbols -> {OUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
