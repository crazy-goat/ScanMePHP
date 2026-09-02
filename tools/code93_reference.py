#!/usr/bin/env python3
"""Regenerate tests/fixtures/code93_reference.csv from zxing-cpp.

Code 93 is three tables and two weighted sums, and all of it fails quietly.
A swapped row in the pattern table gives a symbol that scans as a different
character. A swapped row in the full-ASCII table gives one that scans as a
different string. A wrong weight cycle in either check character gives a symbol
a scanner refuses outright — but only for some payload lengths, because the
cycles are 20 and 15 long and a sum that never wraps cannot show a wrap that is
wrong.

So none of it is transcribed from the standard we implement. It is all written
here by an independent encoder: every one of the 43 data characters, every one
of the 128 ASCII bytes, and payloads long enough to run both weight cycles past
their wrap.

Payloads are stored hex-encoded, as in the Code 39 fixture: half the cases are
control characters, and a CSV holding a raw NUL or CR survives neither an
editor nor a diff.

Run with the decoder venv:  .decoders/bin/python tools/code93_reference.py
"""

import csv
import pathlib
import sys

try:
    import zxingcpp
except ImportError:  # pragma: no cover - developer tooling
    sys.exit("zxing-cpp is missing; run: composer decoders:install")

OUT = pathlib.Path(__file__).resolve().parent.parent / "tests/fixtures/code93_reference.csv"

CHARACTERS = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. $/+%"

CASES = []

# Every ASCII byte on its own, which covers the pattern table exhaustively —
# forty-three of them are data characters — and the other 85 are a shift plus a
# letter, where a wrong shift gives a symbol that scans as the wrong character
# with nothing else out of place. A single-character payload also pins the
# degenerate check calculation, where both weight cycles are one long.
for byte in range(128):
    CASES.append(chr(byte))

# The check characters cycle their weights at 20 and at 15, counting from the
# right. A payload shorter than 15 characters never reaches either wrap, so a
# weight cycle implemented as a plain running index — or reset at the wrong
# point — passes every short case. These are the lengths where it stops.
for length in (14, 15, 16, 19, 20, 21, 34, 35, 40):
    CASES.append("".join(CHARACTERS[i % len(CHARACTERS)] for i in range(length)))

for payload in (
    "AB",
    "ABC123",
    "HELLO WORLD",
    "0123456789",
    "ABCDEFGHIJKLMNOPQRSTUVWXYZ",
    "-. $/+%",
    # The four data characters that Code 39 Extended has to escape and this
    # symbology does not, because its shifts have bars of their own. If they
    # were escaped here, every one of these would be the wrong width and the
    # wrong string.
    "A$B/C+D%E",
    "$100 / 50% + tax",
    "Hello",
    "hello world",
    "a-b_c",
    "MiXeD CaSe 42",
    "Tab\tNewline\n",
    "\x00\x01\x1f\x7f",
    # '*' is not a Code 93 data character and has no special meaning either:
    # the guard is its own pattern, not a character, so unlike Code 39 there is
    # nothing here that could end the symbol early.
    "A*B",
) :
    CASES.append(payload)

# The printable range in chunks. zxing-cpp caps the character count, and most
# printable bytes cost one character here rather than two, so the chunks can be
# larger than the Code 39 fixture's — but not unbounded.
for start in range(32, 127, 40):
    CASES.append("".join(chr(b) for b in range(start, min(start + 40, 127))))

assert len(CASES) == len(set(CASES)), "duplicate case: the fixture keys on the payload"

GUARD = "101011110"


def modules(text: str) -> str:
    barcode = zxingcpp.create_barcode(text, zxingcpp.Code93)
    image = zxingcpp.write_barcode_to_image(barcode, scale=1, add_hrt=False, add_quiet_zones=False)
    view = memoryview(image)
    row = bytearray(view)[: view.shape[1]]
    bars = "".join("1" if pixel < 128 else "0" for pixel in row)

    # Code 93 opens on a bar and closes on the terminator bar, so nothing is
    # cropped and the width is exact. Both guards and a whole number of
    # nine-module characters between them is the shape of every valid symbol.
    assert bars.startswith(GUARD), f"{text!r}: no start guard"
    assert bars.endswith(GUARD + "1"), f"{text!r}: no stop guard or terminator"
    body = len(bars) - 2 * len(GUARD) - 1
    assert body % 9 == 0, f"{text!r}: {body} modules is not a whole number of characters"
    # Two of those characters are the check pair, which is never optional.
    assert body // 9 >= 3, f"{text!r}: too few characters for a payload plus two checks"

    return bars


def main() -> int:
    rows = []
    for data in CASES:
        try:
            rows.append((data.encode().hex(), modules(data)))
        except Exception as error:  # pragma: no cover - developer tooling
            sys.stderr.write(f"{data!r}: {error}\n")
            return 1

    with OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["data_hex", "modules"])
        writer.writerows(rows)

    print(f"{len(rows)} reference symbols -> {OUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
