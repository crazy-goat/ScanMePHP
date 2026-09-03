#!/usr/bin/env python3
"""Regenerate tests/fixtures/australia_post_reference.csv from zint.

`tools/four_state.py` says what verifying a four-state code against zint costs
and why the states below are a measurement of zint's drawing rather than
something zint reports. What is worth drawing *here* is decided by where this
symbology can go wrong, and it has three such places, none of them the layout:

  * **The two character tables.** Sixty-four three-bar patterns and ten
    two-bar ones, published as tables and implemented here as the enumeration
    they are. A rule that is one entry out draws a legal character somebody
    else's reader will believe, so every character of the C table appears, and
    every digit of the N table, in every position of the field they can occupy.

  * **The Reed-Solomon parity.** Four codewords over GF(64), and the only part
    of the symbol no payload reaches directly. It is swept: random fields until
    every codeword value 0 to 63 has been drawn in every one of the four
    parity positions, which is what a wrong generator polynomial or a wrong
    field would fail.

  * **The Format Control Code.** Six of them, and three are a caller's choice
    while three follow from the width of the customer field. All six are here,
    each with the same sorting code, so the fixture shows the FCC changing the
    symbol and nothing else changing with it.

zint splits the six codes across four symbologies -- AUSPOST decides between
11, 59 and 62 by input length, and the other three are their own -- so the
fixture carries the format alongside the data, and the test hands it to the
option bag.

Run with the decoder venv:  .decoders/bin/python tools/australia_post_reference.py
"""

import csv
import pathlib
import random
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))

from four_state import states  # noqa: E402

try:
    from pyzint import Barcode
except ImportError:
    print("pyzint is missing; run: composer decoders:install", file=sys.stderr)
    raise SystemExit(1)

OUT = pathlib.Path(__file__).resolve().parent.parent / "tests/fixtures/australia_post_reference.csv"

# The format names are ours -- they are what AustraliaPostOptions takes -- and
# the symbology is zint's. Reply Paid, Routing and Redirection carry a sorting
# code and nothing else, which is why only "standard" is ever given a field.
FORMATS = {
    "standard": Barcode.AUSPOST,
    "reply-paid": Barcode.AUSREPLY,
    "routing": Barcode.AUSROUTE,
    "redirection": Barcode.AUSREDIRECT,
}

CHARACTERS = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 #abcdefghijklmnopqrstuvwxyz"
DIGITS = "0123456789"

# Customer field lengths, and which table each is filled from. The field is
# sized in bars, not characters: five C-table characters and eight N-table
# digits both fill sixteen bars.
CHARACTER_LENGTHS = (5, 10)
NUMERIC_LENGTHS = (8, 15)

SORTING_CODE = "96130590"
BARS = {0: 37, 5: 52, 8: 52, 10: 67, 15: 67}


def payloads() -> list[tuple[str, str]]:
    chosen = [(SORTING_CODE, name) for name in FORMATS]

    # The ends of the sorting code, and a real one.
    for code in ("00000000", "99999999", "12345678", "45671234"):
        chosen.append((code, "standard"))

    # Every C-table character, at the front, the middle and the back of both
    # field widths. The table is an enumeration; an error in it is a run of
    # characters, and a character that draws differently depending on where it
    # sits would be an encoder that had grown a field it should not have.
    for character in CHARACTERS:
        for length in CHARACTER_LENGTHS:
            for position in (0, length // 2, length - 1):
                field = "A" * position + character + "A" * (length - position - 1)
                chosen.append((SORTING_CODE + field, "standard"))

    # And every N-table digit, the same way, in both numeric widths.
    for digit in DIGITS:
        for length in NUMERIC_LENGTHS:
            for position in (0, length // 2, length - 1):
                field = "0" * position + digit + "0" * (length - position - 1)
                chosen.append((SORTING_CODE + field, "standard"))

    # The filler bar, seen alone: a field of nothing but spaces and a field of
    # nothing but zeroes are the two ways to draw a field that says nothing.
    for length, alphabet in ((5, " "), (10, " "), (8, "0"), (15, "0")):
        chosen.append((SORTING_CODE + alphabet * length, "standard"))

    random.seed(37)
    for _ in range(80):
        code = "".join(random.choice(DIGITS) for _ in range(8))
        length = random.choice((0, *CHARACTER_LENGTHS, *NUMERIC_LENGTHS))
        alphabet = DIGITS if length in NUMERIC_LENGTHS else CHARACTERS
        field = "".join(random.choice(alphabet) for _ in range(length))
        chosen.append((code + field, "standard"))

    return chosen


def parity(drawn: str) -> list[str]:
    """The four parity codewords of a symbol, as their three state letters."""
    tail = drawn[-14:-2]
    return [tail[i:i + 3] for i in range(0, 12, 3)]


def main() -> int:
    rows = {}
    seen = [set(), set(), set(), set()]

    for data, name in payloads():
        drawn = states(FORMATS[name], data)
        assert len(drawn) == BARS[len(data) - 8], f"{data}: {len(drawn)} bars"
        rows[(data, name)] = drawn
        for position, codeword in enumerate(parity(drawn)):
            seen[position].add(codeword)

    # Sweep until the parity has been seen taking every value in every
    # position. Nothing about a payload reaches these bars directly, and a
    # generator polynomial that is right for three positions out of four is
    # the failure this is here to make impossible.
    sweep = random.Random(64)
    while any(len(values) < 64 for values in seen):
        code = "".join(sweep.choice(DIGITS) for _ in range(8))
        field = "".join(sweep.choice(CHARACTERS) for _ in range(10))
        drawn = states(Barcode.AUSPOST, code + field)
        if all(codeword in seen[position] for position, codeword in enumerate(parity(drawn))):
            continue
        rows[(code + field, "standard")] = drawn
        for position, codeword in enumerate(parity(drawn)):
            seen[position].add(codeword)

    with OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["data", "format", "states"])
        writer.writerows(
            [data, name, drawn] for (data, name), drawn in sorted(rows.items())
        )

    widths = {len(drawn) for drawn in rows.values()}
    print(
        f"{len(rows)} reference symbols, widths {sorted(widths)}, "
        f"every parity codeword in every position -> {OUT}"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
