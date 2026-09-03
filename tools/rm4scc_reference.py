#!/usr/bin/env python3
"""Regenerate tests/fixtures/rm4scc_reference.csv from zint.

RM4SCC is the first symbology here that no free decoder reads, so this fixture
is the whole of the outside opinion on it — there is no round trip behind it to
catch what it misses. `tools/four_state.py` says what that arrangement costs
and why the states below are a measurement of zint's drawing rather than
something zint reports; this file is about which payloads are worth drawing.

Nothing in RM4SCC is transcribed: a character is a pair of two-of-four nibbles
and its position in the alphabet is that pair in base six. So the failures this
has to catch are failures of arithmetic, and each one is wrong across a range
rather than in one place:

  * **The alphabet's order.** Every character appears here, because an
    enumeration that pairs the nibbles the other way round — descenders first —
    still draws thirty-six legal characters, all of them somebody else's.

  * **The check character's modulo.** Its nibbles are worth 1 to 6 rather than
    0 to 5, and the two conventions disagree only when a sum is a multiple of
    six. Payloads are included that land on that seam from both sides, and the
    sweep runs until every one of the thirty-six check characters has been
    drawn.

  * **The ends.** A one-character symbol and a fifty-character one, the
    shortest and longest zint will draw.

The fixture holds the symbol's bars as state letters — D, A, F, T, one per bar,
start and stop included — which is what RM4SCC is legible as. Quiet zone is the
encoder's business and is not in here.

Run with the decoder venv:  .decoders/bin/python tools/rm4scc_reference.py
"""

import csv
import pathlib
import random
import string
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))

from four_state import states  # noqa: E402

try:
    from pyzint import Barcode
except ImportError:
    print("pyzint is missing; run: composer decoders:install", file=sys.stderr)
    raise SystemExit(1)

OUT = pathlib.Path(__file__).resolve().parent.parent / "tests/fixtures/rm4scc_reference.csv"

ALPHABET = string.digits + string.ascii_uppercase
MAX_LENGTH = 50

# Ascender nibble value, descender nibble value: 1 to 6 each, in the order the
# alphabet enumerates them. Used here only to choose payloads that sit on the
# seam of the check character's modulo -- the values themselves are what the
# fixture is verifying, so nothing is derived from them.
def nibbles(character: str) -> tuple[int, int]:
    index = ALPHABET.index(character)
    return index // 6 + 1, index % 6 + 1


def check_sums(data: str) -> tuple[int, int]:
    pairs = [nibbles(c) for c in data]
    return sum(a for a, _ in pairs), sum(d for _, d in pairs)


def payloads() -> list[str]:
    chosen = ["0", "Z", "LE28HS", "BX11LT1A", "SW1A1AA9Z", "0" * MAX_LENGTH]

    # Every character, four at a time, so each one is drawn in all four bar
    # positions of a symbol rather than always at the same offset.
    for start in range(0, len(ALPHABET), 4):
        chosen.append(ALPHABET[start:start + 4])
    chosen.append(ALPHABET[:MAX_LENGTH])

    # The check character's seam: payloads whose nibble sums land on a multiple
    # of six and on either side of one. Modulo over 0-5 and over 1-6 agree
    # everywhere else, so this is the only place the rule can be caught.
    for length in (1, 2, 3, 6, 7):
        for character in ALPHABET:
            ascenders, descenders = check_sums(character * length)
            if ascenders % 6 == 0 or descenders % 6 == 0:
                chosen.append(character * length)

    random.seed(11)
    for _ in range(40):
        length = random.randrange(1, 13)
        chosen.append("".join(random.choice(ALPHABET) for _ in range(length)))

    return chosen


def check_character(drawn: str) -> str:
    """The check character zint drew, read back off the symbol."""
    return drawn[-5:-1]


def main() -> int:
    rows = {}
    seen = set()

    for data in payloads():
        drawn = states(Barcode.RM4SCC, data)
        assert len(drawn) == 4 * (len(data) + 1) + 2, f"{data}: {len(drawn)} bars"
        rows[data] = drawn
        seen.add(check_character(drawn))

    # Thirty-six check characters, and no payload chosen so far reaches all of
    # them. Keep drawing until none is missing, the same way the DataBar
    # fixtures reach every finder pattern.
    random.seed(36)
    while len(seen) < len(ALPHABET):
        data = "".join(random.choice(ALPHABET) for _ in range(random.randrange(1, 9)))
        drawn = states(Barcode.RM4SCC, data)
        if check_character(drawn) in seen:
            continue
        seen.add(check_character(drawn))
        rows[data] = drawn

    with OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["data", "states"])
        writer.writerows(sorted(rows.items()))

    characters = {c for data in rows for c in data}
    print(
        f"{len(rows)} reference symbols, {len(characters)}/{len(ALPHABET)} characters, "
        f"{len(seen)}/{len(ALPHABET)} check characters -> {OUT}"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
