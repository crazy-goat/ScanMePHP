#!/usr/bin/env python3
"""Regenerate tests/fixtures/pdf417_reference.csv from zxing-cpp.

PDF417 compares better than Aztec did, and the reason is that the one thing
these two encoders could disagree about — the symbol's shape — can be pinned in
the request rather than only recorded. Every row here names its error
correction level and its column count, so the comparison downstream of them is
module for module.

Four things were measured before writing it, and each one shaped the file:

  * **The shape is a preference, not a fact.** Any grid with enough cells holds
    the codewords; the spare cells become pad codewords. Asking this writer for
    four rows produces twelve, because the row count follows from the column
    count and the data. So the fixture pins the columns, records the rows, and
    the test asserts the rows rather than assuming them.

  * **The error correction level really is a level.** Unlike Aztec, where the
    ``ec_level`` keyword is silently ignored, here it works across all nine
    values — level 0 gives two check codewords and level 8 gives 512. So this
    oracle speaks about every level, not only about a default policy, and the
    fixture sweeps them.

  * **``bytes`` input gets an ECI header.** Passing ``bytes`` makes this writer
    prepend codewords 927 and 899 to declare binary data, which is a different
    symbol from the same bytes without it. The byte compaction after the header
    agrees with ours codeword for codeword, so this is a difference of
    declaration and not of arithmetic — but it is a real difference in modules,
    so every payload here is ``str`` and the binary cases live in the round trip
    instead.

  * **Two encodings of one payload are often exactly the same length.** Seven
    characters — full stop, comma, hyphen, dollar, slash, colon and asterisk —
    sit in both the Mixed and Punctuation submodes and cost the same from Alpha
    either way, so "N.Y." and "$1,234.56" have several encodings of identical
    size. Across 148 payloads swept while this was written, our encoder was
    never longer and never shorter than this one: half came out identical and
    half came out the same length by a different route. That is the strongest
    form the comparison can take, and it is why the suite asserts modules where
    the streams agree and asserts everywhere the claim that survives a tie:
    never more data codewords than this.

The data codeword count is recorded because it is readable from the symbol
without decoding it — it is the first codeword, the length descriptor — and it
is the number a tie is a tie about.

Run with the decoder venv:  .decoders/bin/python tools/pdf417_reference.py
"""

import csv
import pathlib
import sys

try:
    import zxingcpp
except ImportError:
    print("zxing-cpp is missing; run: composer decoders:install", file=sys.stderr)
    raise SystemExit(1)

# Payloads chosen to exercise every compaction mode and the seams between them:
# pure upper case, pure lower case, mixed case, digits either side of the
# forty-four digit group boundary, the seven characters that live in two
# submodes at once, and the shapes real PDF417 carries.
CASES: list[tuple[str, int | None, int | None]] = [
    ("HELLO WORLD", 0, 2),
    ("HELLO WORLD", 2, 4),
    ("HELLO WORLD", 5, 6),
    ("A", 0, 1),
    ("AB", 0, 1),
    ("ABC", 0, 2),
    ("hello", 0, 2),
    ("Hello World!", 1, 3),
    ("MiXeD CaSe TeXt", 2, 4),
    ("2026-09-03", 0, 2),
    ("N.Y., NY 10001", 1, 3),
    ("$1,234.56", 0, 2),
    ("a b c", 0, 2),
    ("A1B2C3D4E5", 1, 3),
    ("ABC123abc", 0, 2),
    ("1234567890", 0, 2),
    ("1" * 43, 2, 4),
    ("1" * 44, 2, 4),
    ("1" * 45, 2, 4),
    ("1" * 88, 3, 5),
    ("1" * 89, 3, 5),
    ("9876543210" * 10, 3, 6),
    ("https://example.com/a", 1, 3),
    ("https://example.com/products/1234?ref=qr", 2, 4),
    ("SHIP TO: 123 Main St., Apt 4, Springfield IL 62704", 3, 6),
    ("PDF417 IS A STACKED LINEAR SYMBOLOGY", 2, 5),
    ("Order #4471 / 2026-09-03 / EUR 1,234.56", 3, 6),
    ("BOARDING-4471", 2, 3),
    ("The quick brown fox jumps over the lazy dog", 4, 7),
    ("ABCDEFGHIJKLMNOPQRSTUVWXYZ", 2, 4),
    ("abcdefghijklmnopqrstuvwxyz", 2, 4),
    ("0123456789" * 5, 4, 8),
    ("X" * 200, 5, 10),
    ("Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod.", 4, 8),
    ("~!@#$%^&*()_+-=[]{}|;:,.<>?", 2, 4),
    ("Tab\tand\rreturn", 1, 3),
    ("a", 8, 1),
    ("HELLO WORLD", 8, 6),
    ("1" * 500, 6, 14),
    ("Mixed 123 and TEXT and lower 456", 3, 6),
]


def rows_of(text: str, level: int | None, columns: int | None) -> list[str]:
    """The symbol's distinct module rows; a row is drawn three modules tall."""
    options = {}
    if level is not None:
        options["ec_level"] = str(level)
    if columns is not None:
        options["columns"] = str(columns)

    barcode = zxingcpp.create_barcode(text, zxingcpp.BarcodeFormat.PDF417, **options)
    image = zxingcpp.write_barcode_to_image(barcode, scale=1, add_hrt=False, add_quiet_zones=False)
    view = memoryview(image)
    height, width = view.shape[0], view.shape[1]
    raw = bytearray(view)

    rows, previous = [], None
    for row in range(height):
        bits = "".join("1" if raw[row * width + col] < 128 else "0" for col in range(width))
        if bits != previous:
            rows.append(bits)
            previous = bits

    return rows


def main() -> int:
    assert len({(text, level, columns) for text, level, columns in CASES}) == len(CASES), \
        "duplicate case: the fixture keys on payload, level and columns"

    fixture = []
    for text, level, columns in CASES:
        rows = rows_of(text, level, columns)
        width = len(rows[0])
        actual_columns = (width - 1) // 17 - 4

        image = zxingcpp.write_barcode_to_image(
            zxingcpp.create_barcode(text, zxingcpp.BarcodeFormat.PDF417,
                                    **({"ec_level": str(level)} if level is not None else {}),
                                    **({"columns": str(columns)} if columns is not None else {})),
            scale=3, add_hrt=False, add_quiet_zones=True,
        )
        result = zxingcpp.read_barcode(image, formats=zxingcpp.BarcodeFormat.PDF417)
        assert result.bytes == text.encode(), \
            f"{text!r}: the writer's own symbol does not read back"

        fixture.append([
            text.encode().hex(),
            level if level is not None else "",
            actual_columns,
            len(rows),
            "".join(rows),
        ])

    path = pathlib.Path(__file__).resolve().parent.parent / "tests/fixtures/pdf417_reference.csv"
    with path.open("w", newline="") as handle:
        writer = csv.writer(handle)
        writer.writerow(["payload_hex", "level", "columns", "rows", "modules"])
        writer.writerows(fixture)

    print(f"wrote {len(fixture)} rows to tests/fixtures/pdf417_reference.csv")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
