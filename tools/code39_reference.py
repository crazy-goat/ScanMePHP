#!/usr/bin/env python3
"""Regenerate tests/fixtures/code39_reference.csv from zxing-cpp.

Code 39 is two tables and a modulo, which is exactly the shape of mistake a
self-checking test cannot catch: swap two rows of the pattern table and every
symbol still scans, as different characters. Swap two rows of the full-ASCII
escape table and the symbol scans as a different string in a symbology whose
whole point is that it carries text.

So neither table is transcribed from ISO/IEC 16388. Both are written here by
an independent encoder: every one of the 43 characters on its own, every one of
the 128 ASCII bytes on its own in extended mode, and a handful of longer
payloads to catch an inter-character gap that is right only for single
characters.

The payload is stored hex-encoded. Half the extended cases are control
characters, and a CSV holding a raw NUL or CR is a fixture that survives
neither an editor nor a diff.

Run with the decoder venv:  .decoders/bin/python tools/code39_reference.py
"""

import csv
import pathlib
import sys

try:
    import zxingcpp
except ImportError:  # pragma: no cover - developer tooling
    sys.exit("zxing-cpp is missing; run: composer decoders:install")

OUT = pathlib.Path(__file__).resolve().parent.parent / "tests/fixtures/code39_reference.csv"

CHARACTERS = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. $/+%"

FORMATS = {
    "code39": zxingcpp.Code39Std,
    "code39ext": zxingcpp.Code39Ext,
}

CASES = []

# Every character on its own: the pattern table, proved rather than sampled.
for character in CHARACTERS:
    CASES.append(("code39", character))

# Longer payloads. A single-character symbol is start, character, stop, so an
# inter-character gap that is drawn in the wrong place is still symmetric and
# still passes; these are where it stops being.
for payload in (
    "AB",
    "ABC123",
    "HELLO WORLD",
    "0123456789",
    "ABCDEFGHIJKLMNOPQRSTUVWXYZ",
    "-. $/+%",
    "$$//++%%",
    "A-1.2 3/4+5%6",
    "1234567890" * 3,
):
    CASES.append(("code39", payload))

# Every ASCII byte on its own: 43 of them are characters in their own right and
# 85 are a shift plus a letter, and getting one of those 85 wrong is a symbol
# that reads back as the wrong character with nothing else out of place.
for byte in range(128):
    CASES.append(("code39ext", chr(byte)))

for payload in (
    "Hello",
    "hello world",
    "a-b_c",
    "MiXeD CaSe 42",
    # The four shift characters as data, alone above and in company here. In
    # extended mode they are *not* themselves: '$' is '/D'. An encoder that
    # passes them through unshifted produces a symbol that reads back as
    # something else entirely, and these rows are where that shows up.
    "$/%+",
    "$$//%%++",
    "$100 / 50% + tax",
    "\x00\x01\x1f\x7f",
    "Tab\tNewline\n",
) :
    CASES.append(("code39ext", payload))

# The printable range in full, in chunks: zxing-cpp refuses more than 86
# characters, and in extended mode most bytes cost two, so the sweep has to be
# split. Thirty bytes at a time is comfortably inside the limit either way.
for start in range(32, 127, 30):
    CASES.append(("code39ext", "".join(chr(b) for b in range(start, min(start + 30, 127)))))


assert len(CASES) == len(set(CASES)), "duplicate case: the fixture keys on the payload"


def modules(text: str, fmt) -> str:
    barcode = zxingcpp.create_barcode(text, fmt)
    image = zxingcpp.write_barcode_to_image(barcode, scale=1, add_hrt=False, add_quiet_zones=False)
    view = memoryview(image)
    row = bytearray(view)[: view.shape[1]]
    bars = "".join("1" if pixel < 128 else "0" for pixel in row)

    # Code 39 opens and closes on a bar, so unlike the EAN add-ons nothing is
    # cropped away and the width is exact. Asserting the guard and the
    # 13-modules-per-character stride is what makes that a check rather than an
    # assumption.
    assert bars.startswith("100101101101"), f"{text!r}: no start guard"
    assert bars.endswith("100101101101"), f"{text!r}: no stop guard"
    assert (len(bars) + 1) % 13 == 0, f"{text!r}: {len(bars)} modules is not a whole number of characters"

    return bars


def main() -> int:
    rows = []
    for symbology, data in CASES:
        try:
            rows.append((symbology, data.encode().hex(), modules(data, FORMATS[symbology])))
        except Exception as error:  # pragma: no cover - developer tooling
            sys.stderr.write(f"{symbology} {data!r}: {error}\n")
            return 1

    with OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["symbology", "data_hex", "modules"])
        writer.writerows(rows)

    print(f"{len(rows)} reference symbols -> {OUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
