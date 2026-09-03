#!/usr/bin/env python3
"""Regenerate the GS1 fixtures from zxing-cpp.

Writes two files, because GS1-128 is two things stacked and each can be wrong
without the other noticing:

  tests/fixtures/gs1_ai_reference.csv    the application identifier table
  tests/fixtures/gs1_128_reference.csv   GS1-128, module for module
  tests/fixtures/gs1_dm_reference.csv    GS1 Data Matrix, module for module

The table is the part worth deriving rather than transcribing. It says which
four-digit-or-shorter strings are application identifiers at all, how long each
one's data may be, and — separately, and not implied by the first two — whether
an FNC1 must follow it when another element string comes after. That last
column is the one that bites: AI (402) carries exactly seventeen digits and
*still* needs a separator, because "predefined length" in GS1 means the AI is
on a published list, not that its length happens to be fixed. Reading the
answer out of an implementation that is not ours removes the chance of copying
the list wrong in the same direction as the test that checks it.

So the AI columns are swept exhaustively: every string of two, three and four
digits is offered to the encoder, and the ones it accepts are the table. The
lengths are found by offering data of every length from 1 to 99 — which is how
AIs 7007, 7011 and 8008 turn out to accept a *set* of lengths rather than a
range. The separator column is probed at every legal length, under an assertion
that it never depends on the data, since a rule that did could not be expressed
as a table at all.

What zxing-cpp does not check, and this therefore does not either: character
sets and check digits. It accepts '(3103)00018A' and the date '(11)991301'
happily. tests/Gs1Test.php declares that boundary rather than leaving it
silent.

Run with the decoder venv:  .decoders/bin/python tools/gs1_reference.py
"""

import csv
import pathlib
import sys

try:
    import zxingcpp
except ImportError:  # pragma: no cover - developer tooling
    sys.exit("zxing-cpp is missing; run: composer decoders:install")

ROOT = pathlib.Path(__file__).resolve().parent.parent
AI_OUT = ROOT / "tests/fixtures/gs1_ai_reference.csv"
SYMBOL_OUT = ROOT / "tests/fixtures/gs1_128_reference.csv"
DM_OUT = ROOT / "tests/fixtures/gs1_dm_reference.csv"

# Longer than any AI accepts, so the encoder must reject on length and name the
# AI it resolved while doing so. That name is the only way to tell a real AI
# from a prefix of one: offered "(000)1", the parser reads AI 00 with data 01.
TOO_LONG = "1" * 200

FNC1 = "\x1d"


def create(text: str, fmt=zxingcpp.BarcodeFormat.Code128):
    return zxingcpp.create_barcode(text, fmt, gs1=True)


def resolved(ai: str) -> str | None:
    """The AI the encoder parsed out of ``(ai)``, or None if it rejected it."""
    try:
        create(f"({ai}){TOO_LONG}")
    except Exception as error:
        message = str(error)
        if "Invalid data length" not in message:
            return None
        start = message.index("AI (") + 4
        return message[start : message.index(")", start)]

    raise AssertionError(f"({ai}): 200 characters of data cannot be a legal length")


def lengths(ai: str) -> list[int]:
    accepted = []
    for length in range(1, 100):
        try:
            create(f"({ai}){'0' * length}")
        except Exception:
            continue
        accepted.append(length)

    assert accepted, f"({ai}): accepted by the parser but no data length works"
    return accepted


def needs_separator(ai: str, accepted: list[int]) -> bool:
    """Whether an FNC1 follows this AI's data when another element string does."""
    answers = set()
    for length in accepted:
        symbol = create(f"({ai}){'0' * length}(11)991231")
        answers.add(FNC1.encode() in symbol.bytes)

    assert len(answers) == 1, f"({ai}): separator depends on the data length, which is not a table"
    return answers.pop()


def ai_table() -> list[tuple[str, str, str]]:
    rows = []
    for width in (2, 3, 4):
        for value in range(10**width):
            ai = str(value).zfill(width)
            if resolved(ai) != ai:
                continue

            accepted = lengths(ai)
            rows.append((
                ai,
                # A run as "3-30", anything else spelled out; three AIs accept a
                # set rather than a range and the fixture must not smooth that
                # over into a lie a reader would believe.
                f"{accepted[0]}-{accepted[-1]}"
                if accepted == list(range(accepted[0], accepted[-1] + 1))
                else "|".join(str(n) for n in accepted),
                "separator" if needs_separator(ai, accepted) else "predefined",
            ))

    return rows


SYMBOLS = [
    # The shapes the FNC1 placement rule is made of.
    "(01)09501101020917",
    "(01)09501101020917(3103)000189",
    "(10)ABC123",
    "(10)ABC123(11)991231",
    "(11)991231(10)ABC123",
    "(01)09501101020917(10)ABC123(11)991231",
    "(00)123456789012345678",
    "(402)12345678901234567(10)X",
    "(99)X(99)Y",
    "(90)A(91)B(92)C",
    # Digit runs across an FNC1, where the character-set choice has to decide
    # whether the separator is worth leaving set C for.
    "(10)12345(11)991231",
    "(10)123456(11)991231",
    "(10)1234567(11)991231",
    "(21)12345678901234567890",
    # A payload long enough that a switching mistake compounds.
    "(01)09501101020917(21)ABCDEFGHIJ(10)LOT0001(11)260101(17)261231",
]


DM_SYMBOLS = [
    # All-digit element strings, where the FNC1 codeword lands between digit
    # pairs and a pairing mistake shifts every codeword after it.
    "(01)09501101020917",
    "(01)09501101020917(3103)000189",
    "(00)123456789012345678",
    "(11)991231(17)261231",
    "(21)123456(11)991231",
    "(10)1234567(11)991231",
    "(01)09501101020917(17)261231(10)123456",
    "(90)1(91)2",
    # And some with letters, which stay in ASCII encodation at these lengths.
    "(10)A",
    "(10)LOT0001",
    "(01)09501101020917(10)LOT0001",
]

# Deliberately absent: anything with a letter run long enough to pay for a C40
# latch, such as "(01)09501101020917(21)ABCDEFGHIJ(10)LOT0001". This writer
# switches encodation there and we do not implement C40, so the symbols stop
# being comparable. Gs1Test names that boundary and the decoder round trip
# carries those payloads instead.


def dm_modules(barcode) -> tuple[str, str]:
    """The symbol size and its modules, read row by row."""
    image = zxingcpp.write_barcode_to_image(barcode, scale=1, add_hrt=False, add_quiet_zones=False)
    view = memoryview(image)
    height, width = view.shape[0], view.shape[1]
    raw = bytearray(view)

    modules = "".join(
        "1" if raw[row * width + col] < 128 else "0"
        for row in range(height)
        for col in range(width)
    )

    return f"{height}x{width}", modules


def modules(barcode) -> str:
    image = zxingcpp.write_barcode_to_image(barcode, scale=1, add_hrt=False, add_quiet_zones=False)
    view = memoryview(image)
    row = bytearray(view)[: view.shape[1]]
    bars = "".join("1" if pixel < 128 else "0" for pixel in row)

    assert bars.startswith("11"), "a start code opens on a two-module bar"
    assert bars.endswith("11"), "the stop pattern ends on a two-module bar"

    return bars


def main() -> int:
    rows = ai_table()
    with AI_OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["ai", "lengths", "termination"])
        writer.writerows(rows)
    print(f"{len(rows)} application identifiers -> {AI_OUT}")

    assert len(SYMBOLS) == len(set(SYMBOLS)), "duplicate case: the fixture keys on the element string"

    symbols = []
    for elements in SYMBOLS:
        barcode = create(elements)
        # The payload column is what a scanner reports: the element strings run
        # together with FNC1 where one was needed. Our encoder builds that
        # string itself, so storing it checks the placement rule directly
        # rather than only through the bars it produces.
        symbols.append((elements, barcode.bytes.hex(), modules(barcode)))

    with SYMBOL_OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["elements", "payload_hex", "modules"])
        writer.writerows(symbols)
    print(f"{len(symbols)} reference symbols -> {SYMBOL_OUT}")

    assert len(DM_SYMBOLS) == len(set(DM_SYMBOLS)), "duplicate case: the fixture keys on the element string"

    # The size is recorded and the test forces it, because the two encoders
    # disagree about which symbol to reach for — this writer takes the smallest
    # by area and will pick a rectangle, ours prefers a square unless asked.
    # That is a choice rather than a fact about the encoding, and pinning it
    # would test the preference instead of the codewords. What the comparison
    # is for is everything downstream of the size: the FNC1 codewords, digit
    # pairing around them, Reed-Solomon, placement and the finder frame.
    #
    # These payloads all stay in ASCII encodation on both sides. Longer
    # letter runs would not: this writer switches to C40, which we do not
    # implement, and the symbols stop being comparable. See AsciiEncodation.
    matrices = []
    for elements in DM_SYMBOLS:
        barcode = create(elements, zxingcpp.BarcodeFormat.DataMatrix)
        size, bits = dm_modules(barcode)
        matrices.append((elements, size, barcode.bytes.hex(), bits))

    with DM_OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["elements", "size", "payload_hex", "modules"])
        writer.writerows(matrices)
    print(f"{len(matrices)} reference matrices -> {DM_OUT}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
