#!/usr/bin/env python3
"""Regenerate tests/fixtures/kix_reference.csv from zint.

KIX has no free decoder either, so this fixture is the whole of the outside
opinion on it; `tools/four_state.py` says what that arrangement costs and why
the states below are a measurement of zint's drawing rather than something zint
reports.

What is worth drawing here is not what was worth drawing for RM4SCC, because
KIX is a strictly smaller symbology: a payload's bars are its characters' bars
concatenated, with no start pattern, no stop pattern and no check character to
get wrong. So the failures left are exactly two, and both are about the
alphabet rather than the symbol:

  * **The alphabet's order.** Every character appears here. An enumeration
    that pairs the nibbles the other way round — descenders first — still
    draws thirty-six legal characters, all of them somebody else's.

  * **The alphabet's independence of position.** A character has to draw the
    same four bars wherever it sits, so each one is drawn alone, at the front
    of a symbol, at the back, and in the middle. This is the property that
    would catch an encoder which had quietly grown an RM4SCC-shaped envelope.

The ends are here too: a one-character symbol and an eighteen-character one,
the shortest and the longest KIX defines.

The fixture holds the symbol's bars as state letters — D, A, F, T, one per bar.
Quiet zone is the encoder's business and is not in here.

Run with the decoder venv:  .decoders/bin/python tools/kix_reference.py
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

OUT = pathlib.Path(__file__).resolve().parent.parent / "tests/fixtures/kix_reference.csv"

ALPHABET = string.digits + string.ascii_uppercase
MAX_LENGTH = 18


def payloads() -> list[str]:
    # The real thing: a Dutch postcode, house number and addition, as PostNL
    # writes it and as every KIX example in the wild is shaped.
    chosen = ["2500GG30250", "1013AV23XA", "6545CA3B", "0", "Z"]

    # Every character alone, then every character at the front, the back and
    # the middle of a symbol -- the same four bars each time or the encoder is
    # doing something with position that KIX does not.
    for character in ALPHABET:
        chosen.append(character)
        chosen.append(character + "2500GG")
        chosen.append("2500GG" + character)
        chosen.append("250" + character + "0GG")

    # The ends, and the alphabet run through at full length.
    chosen.append(ALPHABET[:MAX_LENGTH])
    chosen.append(ALPHABET[-MAX_LENGTH:])
    chosen.append("9" * MAX_LENGTH)

    random.seed(18)
    for _ in range(40):
        length = random.randrange(1, MAX_LENGTH + 1)
        chosen.append("".join(random.choice(ALPHABET) for _ in range(length)))

    return chosen


def main() -> int:
    rows = {}

    for data in payloads():
        drawn = states(Barcode.KIX, data)
        assert len(drawn) == 4 * len(data), f"{data}: {len(drawn)} bars"
        rows[data] = drawn

    # The claim the whole symbology rests on, checked against zint rather than
    # against ourselves: the bars of a payload are the bars of its characters,
    # in order, with nothing added at either end.
    alone = {c: rows[c] for c in ALPHABET}
    for data, drawn in rows.items():
        assert drawn == "".join(alone[c] for c in data), f"{data}: not a concatenation"

    with OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["data", "states"])
        writer.writerows(sorted(rows.items()))

    characters = {c for data in rows for c in data}
    lengths = {len(data) for data in rows}
    print(
        f"{len(rows)} reference symbols, {len(characters)}/{len(ALPHABET)} characters, "
        f"lengths {min(lengths)} to {max(lengths)} -> {OUT}"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
