#!/usr/bin/env python3
"""Regenerate tests/fixtures/databar_expanded_reference.csv from zxing-cpp.

Expanded is the largest thing in this repository that had to be measured rather
than read, so this fixture is the record of what was measured. Everything below
was established against this writer, and every item of it produces a plausible
symbol when wrong:

  * **The symbol is 4 + 17c + 15*ceil(c/2) modules** for c symbol characters,
    laid out guard, character, finder, character, character, finder, character,
    ..., guard, with no quiet zone drawn either side. The first character is the
    check character.

  * **The whole symbol is one alternating run of elements.** Nothing says
    whether a character opens with a bar or a space except where it sits, so a
    character's table rows are element *positions*. A character's widths are
    drawn forward for the left of a pair and reversed for the right; the finder
    between them is reversed only in odd-numbered pairs.

  * **Five character groups**, boundaries 348, 1388, 2948 and 3988, odd-element
    sums 12, 10, 8, 6, 4 against even sums 5, 7, 9, 11, 13, widest odd
    7, 5, 4, 3, 1 and widest even 2, 4, 5, 6, 8, the narrow-element rule on the
    odd elements, the value's even part varying fastest with divisors
    4, 20, 52, 104 and 204. The last divisor exceeds its own group: 204 even
    combinations for 108 values, so two thirds of that group is never drawn.
    Found by driving the last data character through all 4096 values (the low
    twelve bits of the AI 01 item field) and reading back what was drawn.

  * **Six finder patterns**, chosen by the number of pairs alone and not by the
    checksum — which is where Omnidirectional and Limited differ from this one.
    The sequence per length is a table with no rule behind it.

  * **The checksum is 3^(8k+j) mod 211 over the characters' widths**, where k
    comes from a per-length table that is a scramble for lengths under
    fourteen and the identity above it, and the check character's value is
    211 x (characters - 3) plus the residue. Solved for as a linear system over
    GF(211), one length at a time.

  * **The bit stream is 12-bit slices** of an encodation that packs decimal
    fields ten bits per three digits, not as binary integers. That is why the
    AI 01 item reference is 40 bits and not the 44 a plain integer needs, and
    why a symbol's characters carry decimal-aligned pieces of the number. The
    carry landing on 4000 rather than 4096 is what gave this away.

  * **Numeric mode is seven bits per digit pair**, 11 x d1 + d2 + 8, with the
    FNC1 counting as the digit ten. Eleven bits looks right for a long time
    because the general purpose method's five-bit prefix pads it out to the same
    place; the AI 01 method's field, which starts at bit 48, is what separates
    the two.

  * **An FNC1 written from alphanumeric or ISO 646 mode returns the field to
    numeric mode.** Reading the AI digits that follow it as a numeric pair with
    no latch between is what showed this.

  * **A final lone digit may be four bits, as d + 1**, and the standard's
    encoders do it exactly when it saves a symbol character.

Run with the decoder venv:  .decoders/bin/python tools/databar_expanded_reference.py
"""

import csv
import pathlib
import random
import re
import sys

try:
    import zxingcpp
except ImportError:
    print("zxing-cpp is missing; run: composer decoders:install", file=sys.stderr)
    raise SystemExit(1)

OUT = pathlib.Path(__file__).resolve().parent.parent / "tests/fixtures/databar_expanded_reference.csv"

CHARACTER_MODULES = 17
FINDER_MODULES = 15
GUARD_MODULES = 4
GROUP_BOUNDARIES = [0, 347, 348, 1387, 1388, 2947, 2948, 3987, 3988, 4095]

ALPHA = "ABCDEFGHIJKLMNOPQRSTUVWXYZ"
LOWER = ALPHA.lower()
DIGITS = "0123456789"
# The parenthesised input form cannot express a parenthesis, so those two of the
# eighty-two characters are unreachable from here.
PUNCTUATION = "!\"%&'*+,-./:;<=>?_ "


def modules(data: str) -> str:
    barcode = zxingcpp.create_barcode(data, zxingcpp.BarcodeFormat.DataBarExp)
    svg = zxingcpp.write_barcode_to_svg(barcode)
    width = int(re.search(r'width="(\d+)"', svg).group(1))
    row = ["0"] * width
    for bar in re.finditer(r"M(\d+) 0h(\d+)v", svg):
        start, span = int(bar.group(1)), int(bar.group(2))
        for x in range(start, start + span):
            row[x] = "1"

    drawn = "".join(row)
    for characters in range(2, 23):
        if GUARD_MODULES + CHARACTER_MODULES * characters + FINDER_MODULES * ((characters + 1) // 2) == width:
            return drawn

    raise AssertionError(f"{data}: {width} modules is no symbol length")


def check_digit(thirteen: str) -> str:
    total = sum((3 if i % 2 == 0 else 1) * int(c) for i, c in enumerate(thirteen))
    return str((10 - total % 10) % 10)


def gtin(value: int) -> str:
    thirteen = f"{value:013d}"
    return thirteen + check_digit(thirteen)


def item_reaching(character: int) -> str | None:
    """An AI 01 payload whose last data character holds `character`.

    The last twelve bits of the item reference are two bits of its third
    three-digit field and all ten of its fourth, so a value whose low ten bits
    exceed 999 cannot be reached this way.
    """
    low, high = character % 1024, character // 1024
    if low > 999:
        return None

    return "(01)" + gtin(int(f"{high:03d}000{low:03d}"))


def payloads() -> list[str]:
    chosen = []

    # Every symbol length, which is every finder sequence and every checksum
    # weighting row. Numeric data grows the symbol one character at a time, and
    # AI 90 stops at thirty characters, so the long half needs two elements.
    for length in range(1, 31):
        chosen.append("(90)" + "1" * length)
        chosen.append("(90)" + (ALPHA * 2)[:length])
    for length in range(1, 27):
        chosen.append("(90)" + "1" * 30 + "(91)" + "1" * length)
    for length in range(1, 12):
        chosen.append("(90)" + ALPHA[:20] + "(91)" + ALPHA[:length])

    # Both encodation methods, and the AI 01 field on its own.
    for value in (0, 1, 999, 1000, 999999999999, 123456789012):
        chosen.append("(01)" + gtin(value))
    chosen.append("(01)" + gtin(9001234567890 % 10**13))

    # Every character group, driven through the last data character.
    for boundary in GROUP_BOUNDARIES:
        reached = item_reaching(boundary)
        if reached is not None:
            chosen.append(reached)

    # The three modes and the latches between them, including the thresholds
    # where a run of digits or of capitals becomes worth switching for.
    for run in range(1, 13):
        chosen.append("(90)a" + "1" * run)
        chosen.append("(90)a" + "B" * run)
        chosen.append("(90)a" + "1" * run + "z")
        chosen.append("(90)a" + "B" * run + "z")
        chosen.append("(90)A" + "1" * run)
        chosen.append("(90)A" + "1" * run + "Z")
        chosen.append("(91)a" + "1" * run + "(92)1234")
        chosen.append("(91)a" + "B" * run + "(92)1234")

    # The whole character set, and the FNC1 between two elements.
    chosen.append("(90)" + ALPHA)
    chosen.append("(90)" + LOWER)
    chosen.append("(90)" + PUNCTUATION)
    chosen.append("(10)A(21)B")
    chosen.append("(01)09501101020917(10)LOT0001")
    chosen.append("(01)09501101020917(3103)001750")
    chosen.append("(01)09501101020917(21)SERIAL(11)991201")

    random.seed(24)
    pools = [DIGITS, ALPHA, LOWER, ALPHA + DIGITS, LOWER + DIGITS, ALPHA + LOWER + DIGITS + PUNCTUATION]
    for _ in range(90):
        parts = []
        if random.random() < 0.4:
            parts.append("(01)" + gtin(random.randrange(10**13)))
        for ai in random.sample(["90", "91", "10", "21"], random.randint(1, 2)):
            pool = random.choice(pools)
            parts.append("(%s)%s" % (ai, "".join(random.choice(pool) for _ in range(random.randint(1, 20)))))
        chosen.append("".join(parts))

    return sorted(set(chosen))


def main() -> int:
    rows = []
    for data in payloads():
        try:
            rows.append((data, modules(data)))
        except Exception as error:  # a payload this writer refuses is not a fixture
            print(f"skipped {data}: {error}", file=sys.stderr)

    rows.sort()

    with OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["data", "modules"])
        writer.writerows(rows)

    lengths = {(len(m) - GUARD_MODULES) for _, m in rows}
    print(f"{len(rows)} reference symbols, {len(lengths)} distinct widths -> {OUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
