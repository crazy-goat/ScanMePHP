#!/usr/bin/env python3
"""Regenerate tests/fixtures/code128_reference.csv from zxing-cpp.

Code 128 shipped before this repository had a reference fixture for every
symbology, so it was the one linear code verified only against its own table.
That gap is what this closes.

The table itself is 107 six-element patterns and would be a plausible thing to
get wrong quietly, but the sharper risk is the part that is not a table at all:
the switching between character sets B and C. Set C encodes a digit *pair* per
symbol character, so switching pays for itself from six digits, or from four
when the run ends the payload and no switch back is needed. Get the threshold
wrong and every symbol still scans as the right data — just wider than it needs
to be, or, if the run length and the switch disagree, not at all.

So the cases sweep digit runs of every length from one to twelve, at the front,
in the middle and at the end of a payload, which is where a boundary error
lives. The rest is the printable ASCII range, one character at a time, and the
check character exercised at the lengths where its weighted sum wraps.

Run with the decoder venv:  .decoders/bin/python tools/code128_reference.py
"""

import csv
import pathlib
import sys

try:
    import zxingcpp
except ImportError:  # pragma: no cover - developer tooling
    sys.exit("zxing-cpp is missing; run: composer decoders:install")

OUT = pathlib.Path(__file__).resolve().parent.parent / "tests/fixtures/code128_reference.csv"

CASES = []

# Every printable ASCII character on its own. Set B covers 32 to 126 and this
# implementation ships no set A, so that range is the whole alphabet. The ten
# digits are left to the run sweep below, which covers them as payloads of one.
for byte in range(32, 127):
    if not chr(byte).isdigit():
        CASES.append(chr(byte))

# Digit runs of every length, in the three positions where the set B/C
# threshold can be got wrong: the payload is all digits, the run opens it, the
# run closes it, or the run sits between letters.
for run in range(1, 13):
    digits = "".join(str(d % 10) for d in range(run))
    CASES.append(digits)
    CASES.append(digits + "X")
    CASES.append("X" + digits)
    CASES.append("X" + digits + "X")

for payload in (
    "SHIPMENT-4471",
    "AB123456CD",
    "0123456789012345678901234567890123456789",
    "Code 128",
    "!\"#$%&'()*+,-./",
    ":;<=>?@[\\]^_`{|}~",
    "a" * 60,
    "9" * 61,
):
    CASES.append(payload)

assert len(CASES) == len(set(CASES)), "duplicate case: the fixture keys on the payload"


def modules(payload: str) -> str:
    barcode = zxingcpp.create_barcode(payload, zxingcpp.BarcodeFormat.Code128)
    image = zxingcpp.write_barcode_to_image(barcode, scale=1, add_hrt=False, add_quiet_zones=False)
    view = memoryview(image)
    row = bytearray(view)[: view.shape[1]]
    bars = "".join("1" if pixel < 128 else "0" for pixel in row)

    assert bars.startswith("11"), f"{payload!r}: a start code opens on a two-module bar"
    assert bars.endswith("11"), f"{payload!r}: the stop pattern ends on a two-module bar"

    return bars


def main() -> int:
    rows = []
    for payload in CASES:
        try:
            rows.append((payload.encode().hex(), modules(payload)))
        except Exception as error:  # pragma: no cover - developer tooling
            sys.stderr.write(f"{payload!r}: {error}\n")
            return 1

    with OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["payload_hex", "modules"])
        writer.writerows(rows)

    print(f"{len(rows)} reference symbols -> {OUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
