#!/usr/bin/env python3
"""Regenerate tests/fixtures/maxicode_reference.csv from zxing-cpp.

MaxiCode compares better than most. There is no mask, no version and no error
correction level: one payload in the plain mode is one symbol, so every module
is a fact rather than a preference. What the fixture is really testing is the
code set search — five overlapping repertoires, and which one to be in at each
character — and Reed-Solomon over GF(64) in three blocks.

Four things were measured before writing it, and each one shaped a decision:

  * **The raster cannot be sampled.** MaxiCode's modules are hexagons on offset
    rows, so ``write_barcode_to_image`` does not put one pixel on one module.
    ``write_barcode_to_svg`` names every dark module exactly; that is what this
    reads. The same fact is why the bullseye never appears here: it is three
    circles rather than modules, and it is not part of the module grid at all.

  * **A ``bytes`` payload gets an ECI header** — three codewords this library
    does not emit — so every case here is a ``str``. What a ``str`` becomes is
    then not obvious: 0xA0 to 0xFF go into the symbol as themselves, but 0x80 to
    0x9F go in as their two UTF-8 bytes. Rather than predict that, the fixture
    stores the bytes the reader hands back, which are by definition the ones the
    symbol carries and therefore the ones our own encoder is given.

  * **Only mode 4 is reachable.** zxing-cpp accepts and silently ignores any
    keyword — ``mode=2`` changes nothing — so the oracle can only speak about
    the plain symbol. Modes 2, 3 and 6 are verified by round trip instead, in
    tests/DecoderRoundTripTest.php, which is the stronger check for them
    anyway: what matters about a structured carrier message is that a reader
    reports the postcode as a field.

  * **Ties are the normal case.** A space is carried by all five code sets and a
    comma by three, so two encodings of one payload are regularly the same
    length. The suite asserts modules where the two encoders agree and, for
    every row, the comparison that holds regardless: never more codewords than
    this.

Run with the decoder venv:  .decoders/bin/python tools/maxicode_reference.py
"""

import csv
import pathlib
import re
import sys

try:
    import zxingcpp
except ImportError:  # pragma: no cover - developer tooling
    sys.exit("zxing-cpp is missing; run: composer decoders:install")

ROOT = pathlib.Path(__file__).resolve().parent.parent
OUT = ROOT / "tests/fixtures/maxicode_reference.csv"

ROWS = 33
COLUMNS = 30
PITCH_Y = (29.29 - 1.58) / 32

CASES: list[str] = [
    # One code set at a time, so a mistake in a table cannot hide behind a latch.
    "HELLO WORLD",
    "hello world",
    "0123456789",
    "A.B, C: D",
    "!\"#$%&'()*+,-./",
    ":;<=>?@[]^_`{|}~",
    # Latch or shift. Set B is the only one with a multi-character shift, so
    # one, two and three capitals inside lower case each take a different route
    # and four is where the latch finally wins.
    "abcXdef",
    "abcXYdef",
    "abcXYZdef",
    "abcWXYZdef",
    "HELLOxWORLD",
    "HELLOxyWORLD",
    "HELLOxyzWORLD",
    # Nine digits is the only run that compacts, so these straddle the seam.
    "AB 10001",
    "12345678",
    "123456789",
    "1234567890",
    "123456789012345678",
    "1234567890123456789",
    "3.14159265358979",
    "1,234,567.89",
    # The upper half of Latin-1, which is where sets C, D and E live and where
    # an off-by-one in a table would otherwise never be noticed.
    "\xc0\xc1\xc2\xd9\xda\xdf",
    "\xe0\xe1\xe2\xf9\xfa\xff",
    "\xaa\xac\xb1\xb5\xb9\xbe",
    "\xa1\xa8\xab\xaf\xb0\xbf",
    "\xa0\xa2\xa3\xa9\xad\xb6",
    "A\xc0a\xe0",
    "\xc0\xc0\xc0\xc0\xc0",
    "\xc0\xc0\xc0\xc0\xc0A",
    "\xc0\xc0\xc0\xc0\xc0a",
    # Control characters, which set E holds and set A holds three of.
    "\x1c\x1d\x1e",
    "LINE1\rLINE2",
    # Realistic payloads, and the lengths that reach the capacity.
    "SHIP TO 123 MAIN ST APT 4",
    "HTTPS://EXAMPLE.COM/PRODUCTS/12345",
    "Order 12345, shipped 2026-01-15",
    "A" * 92,
    "A" * 93,
    "1" * 90,
    "MiXeD CaSe TeXt " * 3,
]


def dark_modules(payload: str) -> set:
    barcode = zxingcpp.create_barcode(payload, zxingcpp.BarcodeFormat.MaxiCode)
    path = re.search(r'<path d="([^"]+)"', zxingcpp.write_barcode_to_svg(barcode)).group(1)

    found = set()
    for subpath in (p for p in path.split("Z") if p.strip()):
        points = [(float(a), float(b)) for a, b in re.findall(r"[ML](-?[\d.]+) (-?[\d.]+)", subpath)]
        xs = [p[0] for p in points]
        ys = [p[1] for p in points]
        row = round(((min(ys) + max(ys)) / 2 - 1.58) / PITCH_Y)
        offset = (min(xs) + max(xs)) / 2 - 1.5
        odd = abs(offset - round(offset)) > 0.25
        found.add((row, round(offset - (0.5 if odd else 0))))

    return found


def main() -> int:
    assert len(CASES) == len(set(CASES)), "duplicate case: the fixture keys on the payload"

    rows = []
    for text in CASES:
        dark = dark_modules(text)

        # A fixture row that does not scan is worse than no row: it would
        # freeze a defect. The writer's own symbol has to read back, and what it
        # reads back is the payload the row records.
        image = zxingcpp.write_barcode_to_image(
            zxingcpp.create_barcode(text, zxingcpp.BarcodeFormat.MaxiCode), scale=6
        )
        result = zxingcpp.read_barcode(image, formats=zxingcpp.BarcodeFormat.MaxiCode)
        assert result is not None, f"{text!r}: does not read back"
        payload = result.bytes
        assert payload in (text.encode("latin-1", "ignore"), text.encode()), (
            f"{text!r}: the symbol carries {payload.hex()}, which is neither form of it"
        )

        modules = "".join(
            "1" if (row, column) in dark else "0"
            for row in range(ROWS)
            for column in range(COLUMNS)
        )
        rows.append((payload.hex(), int(result.extra["ECLevel"]), modules))

    with OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["payload_hex", "mode", "modules"])
        writer.writerows(rows)

    print(f"{len(rows)} reference symbols -> {OUT}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
