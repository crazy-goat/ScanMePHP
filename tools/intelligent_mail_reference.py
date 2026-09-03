#!/usr/bin/env python3
"""Regenerate tests/fixtures/intelligent_mail_reference.csv from zint.

No free decoder reads a four-state postal code, so this fixture and the pixel
read-back in DecoderRoundTripTest are the whole of the outside opinion on
Intelligent Mail; `tools/four_state.py` says what that arrangement costs and
why the states here are a measurement of zint's drawing rather than something
zint reports. This file is about which payloads are worth drawing.

Almost nothing in this symbology is local, which changes what a payload can
catch. In RM4SCC a wrong character is a wrong group of four bars and every
other bar is untouched; here one digit moves most of the sixty-five, so a
single mismatched symbol says the encoder is wrong somewhere without saying
where. What the payloads below do is make sure each rule is the *only* thing
that differs between two rows somewhere in the file:

  * **The four routing code lengths**, and the offsets that keep them apart.
    A missing routing code and one of five zeroes are here side by side, which
    is the pair that catches an encoder treating the routing code as a number.
  * **The second digit of the barcode identifier**, all five of its values,
    because it is the one digit worth five rather than ten.
  * **Every bit of the frame check sequence, set and clear.** Each of the ten
    low bits inverts one character; the eleventh is spent twice, doubling the
    last codeword and adding 659 to the first. The sweep runs until all
    twenty-two states have been drawn, and until the first codeword has been
    seen on both sides of 659 — the seam that bit sits on.
  * **The ends.** All zeroes, and the largest payload the symbology defines.

Sixty random payloads follow those, for the rule nobody thought to write down.

Run with the decoder venv:  .decoders/bin/python tools/intelligent_mail_reference.py
"""

import csv
import pathlib
import random
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))

from four_state import states  # noqa: E402
from intelligent_mail import codewords, frame_check, value, zint  # noqa: E402

try:
    from pyzint import Barcode
except ImportError:
    print("pyzint is missing; run: composer decoders:install", file=sys.stderr)
    raise SystemExit(1)

OUT = pathlib.Path(__file__).resolve().parent.parent / "tests/fixtures/intelligent_mail_reference.csv"

CHECK_BITS = 11
FIRST_RADIX = 659


def chosen() -> list[tuple[str, str]]:
    picked = [
        # The example USPS-B-3200 works through, at every routing code length.
        ("01234567094987654321", ""),
        ("01234567094987654321", "01234"),
        ("01234567094987654321", "012345678"),
        ("01234567094987654321", "01234567891"),
        # Nothing, and everything.
        ("00000000000000000000", ""),
        ("00000000000000000000", "00000"),
        ("00000000000000000000", "00000000000"),
        ("94999999999999999999", "99999999999"),
        # A real service type and mailer identifier, six and nine digits.
        ("00040123456203000000", ""),
        ("00700002821115802003", "12345678901"),
    ]

    # The endorsement digit, all five of it, against an otherwise fixed payload.
    picked += [(f"0{digit}234567094987654321", "12345") for digit in range(5)]

    # One digit apart, at each end of the tracking code and in the routing
    # code: three pairs where the fixture says what a single digit moves.
    picked += [
        ("11234567094987654321", "12345"),
        ("01234567094987654322", "12345"),
        ("01234567094987654321", "12346"),
    ]

    # A seeded random sweep, because the rules above are the ones somebody
    # thought of. Sixty payloads across all four routing code lengths cost
    # nothing to keep and are what would catch a rule nobody wrote down.
    random.seed(20)
    for _ in range(60):
        tracking = "%d%d" % (random.randrange(10), random.randrange(5))
        tracking += "".join(random.choice("0123456789") for _ in range(18))
        length = random.choice([0, 5, 9, 11])
        picked.append((tracking, "".join(random.choice("0123456789") for _ in range(length))))

    return picked


def sweep(rows: dict) -> None:
    """Keep drawing until every check bit and both sides of 659 are covered."""
    wanted = {(bit, bit_set) for bit in range(CHECK_BITS) for bit_set in (0, 1)}
    wanted |= {("first", 0), ("first", 1)}

    def reached(tracking: str, routing: str) -> set:
        number = value(tracking, routing)
        fcs = frame_check(number)
        seen = {(bit, (fcs >> bit) & 1) for bit in range(CHECK_BITS)}
        seen.add(("first", 1 if codewords(number, fcs)[0] >= FIRST_RADIX else 0))
        return seen

    for tracking, routing in rows:
        wanted -= reached(tracking, routing)

    random.seed(65)
    while wanted:
        tracking = "%d%d" % (random.randrange(10), random.randrange(5))
        tracking += "".join(random.choice("0123456789") for _ in range(18))
        routing = "".join(
            random.choice("0123456789") for _ in range(random.choice([0, 5, 9, 11]))
        )

        reaching = reached(tracking, routing)
        if not reaching & wanted:
            continue

        wanted -= reaching
        rows[(tracking, routing)] = None


def main() -> int:
    rows = dict.fromkeys(chosen())
    sweep(rows)

    drawn = {}
    for tracking, routing in rows:
        symbol = states(Barcode.ONECODE, zint(tracking, routing))
        assert len(symbol) == 65, f"{tracking}-{routing}: {len(symbol)} bars"
        drawn[zint(tracking, routing)] = symbol

    with OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["data", "states"])
        writer.writerows(sorted(drawn.items()))

    lengths = {len(data.split("-")[1]) if "-" in data else 0 for data in drawn}
    print(f"{len(drawn)} reference symbols, routing code lengths {sorted(lengths)} -> {OUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
