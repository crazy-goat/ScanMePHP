#!/usr/bin/env python3
"""Regenerate tests/fixtures/ean_upc_reference.csv from zxing-cpp.

The EAN/UPC family is the one place where a transcription slip in a parity
table produces a symbol that still looks plausible, so the module patterns are
not taken from the standard we implement: they are written here by an
independent encoder and checked bit for bit.

Run with the decoder venv:  .decoders/bin/python tools/ean_upc_reference.py
"""

import csv
import pathlib
import sys

try:
    import zxingcpp
except ImportError:  # pragma: no cover - developer tooling
    sys.exit("zxing-cpp is missing; run: composer decoders:install")

OUT = pathlib.Path(__file__).resolve().parent.parent / "tests/fixtures/ean_upc_reference.csv"

FORMATS = {
    "ean13": zxingcpp.EAN13,
    "ean8": zxingcpp.EAN8,
    "upc-a": zxingcpp.UPCA,
    "upc-e": zxingcpp.UPCE,
    "ean2": zxingcpp.EAN2,
    "ean5": zxingcpp.EAN5,
}


def check_digit(payload: str) -> int:
    total = 0
    for index, digit in enumerate(payload):
        weight = 3 if (len(payload) - 1 - index) % 2 == 0 else 1
        total += int(digit) * weight
    return (10 - total % 10) % 10


def with_check(payload: str) -> str:
    return payload + str(check_digit(payload))


CASES = [
    ("ean13", with_check("590123412345")),
    ("ean13", with_check("400638133393")),
    ("ean13", with_check("978837578064")),
    ("ean13", with_check("000000000000")),
    ("ean8", with_check("9638507")),
    ("ean8", with_check("1234567")),
    ("ean8", with_check("0000000")),
    ("ean8", with_check("4017072")),
    ("ean8", with_check("7351353")),
    ("upc-a", with_check("03600029145")),
    ("upc-a", with_check("01234567890")),
    ("upc-a", with_check("00000000000")),
    ("upc-a", with_check("04210000526")),
    ("upc-a", with_check("99999999999")),
]

def expand(system: str, six: str) -> str:
    """UPC-E's six data digits back to the eleven-digit UPC-A payload."""
    last = int(six[5])
    if last <= 2:
        body = six[:2] + six[5] + "0000" + six[2:5]
    elif last == 3:
        body = six[:3] + "00000" + six[3:5]
    elif last == 4:
        body = six[:4] + "00000" + six[4]
    else:
        body = six[:5] + "0000" + six[5]
    return system + body


def compressible(six: str) -> bool:
    """The zero-suppression rules do not overlap, and the spec says so here.

    Without these, two different UPC-E symbols would expand to the same UPC-A,
    which is why a payload the rules forbid has to be refused rather than
    encoded.
    """
    last = int(six[5])
    if last == 3:
        return six[2] not in "012"
    if last == 4:
        return six[3] != "0"
    if last >= 5:
        return six[4] != "0"
    return True


# UPC-E is the one member of the family whose parity pattern is chosen by the
# check digit rather than by a printed digit, so the fixture covers all twenty
# (number system, check digit) pairs, and every compression branch: getting
# rule 3 or 4 wrong is invisible under every other rule.
seen = set()
for system in ("0", "1"):
    for last in range(10):
        for prefix in range(100000):
            six = f"{prefix:05d}{last}"
            if not compressible(six):
                continue
            key = (system, check_digit(expand(system, six)))
            if key in seen:
                continue
            seen.add(key)
            CASES.append(("upc-e", system + six + str(key[1])))
            break
assert len(seen) == 20, f"only {len(seen)} of 20 parity patterns covered"

# The add-ons. EAN-2 has only a hundred possible symbols, so all of them are
# here: that is exhaustive proof of the modulo-4 parity table rather than a
# sample of it. EAN-5 has a hundred thousand, so it gets one symbol per
# checksum — every parity pattern the table can select — plus the edges.
for value in range(100):
    CASES.append(("ean2", f"{value:02d}"))


def addon_check(digits: str) -> int:
    return sum(int(d) * (3 if i % 2 == 0 else 9) for i, d in enumerate(digits)) % 10


seen5 = set()
for value in range(100000):
    digits = f"{value:05d}"
    check = addon_check(digits)
    if check in seen5:
        continue
    seen5.add(check)
    CASES.append(("ean5", digits))
assert len(seen5) == 10, f"only {len(seen5)} of 10 parity patterns covered"

for digits in ("00000", "99999", "51234", "90000"):
    if ("ean5", digits) not in CASES:
        CASES.append(("ean5", digits))


# Expected module count per symbology, used to restore the one bit the writer
# drops: an add-on's guard opens with a space, and an image written without
# quiet zones starts at the first bar. The missing module is forced by the
# guard, not guessed — asserting the width here is what keeps it that way.
WIDTHS = {"ean13": 95, "ean8": 67, "upc-a": 95, "upc-e": 51, "ean2": 21, "ean5": 48}


def modules(text: str, fmt, expected: int) -> str:
    barcode = zxingcpp.create_barcode(text, fmt)
    image = zxingcpp.write_barcode_to_image(barcode, scale=1, add_hrt=False, add_quiet_zones=False)
    view = memoryview(image)
    width = view.shape[1]
    row = bytearray(view)[:width]
    bars = "".join("1" if pixel < 128 else "0" for pixel in row)

    missing = expected - len(bars)
    if missing:
        assert missing == 1, f"{text}: expected {expected} modules, got {len(bars)}"
        bars = "0" + bars
    assert bars.startswith("0101") or bars.startswith("101"), f"{text}: no start guard"

    return bars


def main() -> int:
    rows = []
    for symbology, data in CASES:
        try:
            rows.append((symbology, data, modules(data, FORMATS[symbology], WIDTHS[symbology])))
        except Exception as error:  # pragma: no cover - developer tooling
            return int(bool(sys.stderr.write(f"{symbology} {data}: {error}\n"))) or 1

    with OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["symbology", "data", "modules"])
        writer.writerows(rows)

    print(f"{len(rows)} reference symbols -> {OUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
