#!/usr/bin/env python3
"""Regenerate tests/fixtures/aztec_reference.csv from zxing-cpp.

Aztec compares better than QR did. There is no mask, so a symbol is fully
determined by its payload once the size is settled, and the size is settled by
a policy both encoders share: the smallest symbol that holds the data with at
least the recommended error correction. So this is a real module-for-module
oracle, not a recording of one encoder's preferences.

Four things were measured before writing it, and each one shaped a column:

  * **The ``ec_level`` keyword is silently ignored for Aztec by this build.**
    zxing accepts any keyword — ``nonsense_kwarg='1'`` passes too — so a caller
    who thinks they asked for 50% gets the default and no warning. The oracle
    therefore only ever speaks about the default policy, which is why our own
    default has to be the same one for these rows to mean anything.

  * **The error correction percentage is a result, not an instruction.** Five
    characters land in a 15x15 symbol and get 52% because that is the slack
    left over, not because anything asked for it. Aztec's parameter is a
    *minimum*; whatever capacity the chosen layer count does not need for data
    becomes error correction. The percentage is recorded to document that, and
    the test does not assert it — asserting a derived number would just restate
    the layer count.

  * **Two different percentages describe the same symbol.** The writer reports
    52% and the reader 70% for that 15x15: different denominators, one over the
    total codewords and one over the data. Our option names its unit rather
    than leaving the caller to guess which convention it follows.

  * **A ``str`` payload is encoded as UTF-8, so byte-level cases must be
    ``bytes``.** Passing ``chr(0x80)`` puts ``c2 80`` in the symbol and quietly
    tests something else. Every payload here is bytes and the fixture stores
    hex, which is also the only honest column type for a symbology whose whole
    point includes a binary shift.

The layer count is recorded because the reader reports it and it is the one
structural fact worth pinning separately from the modules: a symbol can be the
right size for the wrong reason if compact and full are confused.

The data word count is recorded because the two encoders do not always agree on
the bit stream, and the count says which kind of disagreement it is. Aztec's
five modes overlap enough that two encodings of the same payload can be exactly
the same length — "HELLOxWORLD" is one, where a single lower-case letter costs
either a latch and the nine bits to get back or an eighteen-bit binary shift,
and both come to twelve data words. Neither is wrong. And on
"https://example.com/a" this writer takes twenty-four data words where the
encoder here takes twenty-three, so the disagreement runs the other way. So the
suite asserts modules where the streams agree, and everywhere asserts the
comparison that holds regardless: never more data words than this.

Run with the decoder venv:  .decoders/bin/python tools/aztec_reference.py
"""

import csv
import pathlib
import sys

try:
    import zxingcpp
except ImportError:  # pragma: no cover - developer tooling
    sys.exit("zxing-cpp is missing; run: composer decoders:install")

ROOT = pathlib.Path(__file__).resolve().parent.parent
OUT = ROOT / "tests/fixtures/aztec_reference.csv"

COMPACT_SIZES = (15, 19, 23, 27)

TEXT_PAYLOADS: list[str] = [
    # One mode at a time, so a mistake in a character table cannot hide behind
    # a latch decision.
    "HELLO",
    "hello",
    "0123456789",
    "A.B, C: D",
    "\x01\x02\x03",
    # Latch or shift. A single foreign character is cheaper shifted; a run is
    # cheaper latched, and the crossover is what a greedy encoder gets wrong.
    "HELLOxWORLD",
    "HELLOxyWORLD",
    "HELLOxyzWORLD",
    "helloXworld",
    "AB1CD",
    "AB12CD",
    "AB123CD",
    "AB1234CD",
    # The two-character punctuation codes, which an encoder that only knows
    # single characters pays double for.
    "END. NEXT",
    "ONE, TWO",
    "KEY: VALUE",
    "LINE1\r\nLINE2",
    "A\r\nB. C, D: E",
    # Digit mode cannot hold a full stop or a comma except as punctuation, so
    # decimals and thousands separators are a mode-thrash test.
    "12.34",
    "1,234,567",
    "3.14159265358979",
    # Every character the Mixed and Punct tables hold, which is the part most
    # likely to be transcribed wrongly and the part a realistic payload never
    # reaches.
    "@\\^_`|~\x7f",
    "!\"#$%&'()*+,-./",
    ":;<=>?[]{}",
    "\x01\x02\x03\x04\x05\x06\x07\x08\t\n\x0b\x0c\r",
    "\x1b\x1c\x1d\x1e\x1f",
    # Realistic mixtures, and the lengths that cross a boundary: compact 1 to
    # 4, then compact 4 to full 4 where the codeword size has already gone
    # from six bits to eight.
    "https://example.com/a",
    "https://example.com/products/12345?ref=qr",
    "Order 12345, shipped 2026-01-15. Contact: ops@example.com",
    "A" * 12,
    "A" * 13,
    "A" * 33,
    "A" * 57,
    "A" * 88,
    "A" * 89,
    "A" * 104,
    "A" * 105,
    # The remaining codeword sizes. Aztec changes field with the layer count —
    # six bits for one and two layers, eight up to eight, ten up to twenty-two
    # and twelve beyond — so an implementation can be right in one band and
    # wrong in the next. These three rows are here for the fields, and they
    # carry the extra reference grid lines with them.
    "A" * 351,
    "A" * 483,
    "A" * 1567,
]


def mode_message_cells(size: int, compact: bool):
    """The ring cells that carry the mode message, in the order it is written.

    Anticlockwise is wrong here and clockwise is right: the message starts just
    past the top-left orientation mark and runs left to right along the top,
    down the right, right to left along the bottom and up the left. A full
    symbol's reference grid crosses the ring at the middle of each side, and
    those four cells belong to the grid rather than the message.

    This reads the *oracle's* symbol with geometry this repository derived
    separately, and tests/AztecLayoutTest.php checks every fixed module of every
    size this fixture carries against these same symbols. So the numbers that
    come out are still zxing's own.
    """
    centre = size // 2
    radius = 5 if compact else 7
    low, high = centre - radius, centre + radius

    cells = []
    cells += [(low, i) for i in range(low + 2, high - 1)]
    cells += [(i, high) for i in range(low + 2, high - 1)]
    cells += [(high, i) for i in range(high - 2, low + 1, -1)]
    cells += [(i, low) for i in range(high - 2, low + 1, -1)]

    if not compact:
        cells = [c for c in cells if c[0] != centre and c[1] != centre]

    return cells


def data_words(bits: str, size: int, compact: bool) -> int:
    """How many data codewords the oracle's own mode message declares."""
    cells = mode_message_cells(size, compact)
    message = "".join(bits[y * size + x] for y, x in cells)
    layer_field, word_field = (2, 6) if compact else (5, 11)
    assert len(message) == layer_field + word_field + (20 if compact else 24)

    return int(message[layer_field:layer_field + word_field], 2) + 1


def modules(barcode) -> tuple[int, str]:
    image = zxingcpp.write_barcode_to_image(barcode, scale=1, add_hrt=False, add_quiet_zones=False)
    view = memoryview(image)
    height, width = view.shape[0], view.shape[1]
    assert height == width, f"an Aztec symbol is square, got {height}x{width}"
    raw = bytearray(view)

    return height, "".join(
        "1" if raw[row * width + col] < 128 else "0"
        for row in range(height)
        for col in range(width)
    )


def main() -> int:
    assert len(TEXT_PAYLOADS) == len(set(TEXT_PAYLOADS)), "duplicate case: the fixture keys on the payload"

    rows = []
    for text in TEXT_PAYLOADS:
        payload = text.encode()
        barcode = zxingcpp.create_barcode(text, zxingcpp.BarcodeFormat.Aztec)
        size, bits = modules(barcode)

        # Read it back, both for the layer count and because a fixture row that
        # does not scan is worse than no row: it would freeze a defect.
        image = zxingcpp.write_barcode_to_image(barcode, scale=1, add_hrt=False, add_quiet_zones=False)
        result = zxingcpp.read_barcode(image, formats=zxingcpp.BarcodeFormat.Aztec)
        assert result.bytes == payload, f"{payload.hex()}: the writer's own symbol does not read back"

        compact = size in COMPACT_SIZES
        declared_layers = int(result.extra["Version"])
        rows.append((
            payload.hex(),
            size,
            declared_layers,
            "compact" if compact else "full",
            data_words(bits, size, compact),
            barcode.ec_level.rstrip("%"),
            bits,
        ))

    with OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["payload_hex", "size", "layers", "kind", "data_words", "ec_percent", "modules"])
        writer.writerows(rows)

    print(f"{len(rows)} reference symbols -> {OUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
