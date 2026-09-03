#!/usr/bin/env python3
"""Regenerate tests/fixtures/micro_qr_reference.csv from zint.

Micro QR is in the best position of any symbology here: it has both an encoder
we did not write and a reader we did not write, and they are not the same
project. zint draws the symbols in this file — reached through `pyzint`, with
the version and error correction level pinned rather than left to its own
policy — and zxing-cpp reads them back in DecoderRoundTripTest. So a mistake
has to survive being drawn differently *and* being decoded wrongly, which is
two independent opinions rather than the one that the four-state postal codes
have to make do with.

The pinning matters. Left alone, zint raises the error correction level
whenever the data still fits — 'HELLO' comes out M2-M rather than M2-L — which
is a reasonable policy and also this library's, but a fixture that recorded the
policy could not tell a wrong table from a differently-chosen level. Every row
here names its version and level, so what is being compared is the encoding.

What the payload list is built to catch, in the order the failures actually
happen:

  * **The version tables.** Almost nothing in Micro QR is a constant. The mode
    indicator is nought, one, two or three bits; the character count is a
    different width in every version *and* every mode; the terminator is three,
    five, seven or nine zeroes. That is thirty-odd numbers, and a wrong one
    shifts every bit after it. So every legal combination of version, level and
    mode appears, at its longest payload and at a short one.

  * **The final nibble.** M1 and M3 end on four bits rather than eight, and the
    nibble is left-aligned in the byte Reed-Solomon sees. Getting that backwards
    produces a symbol whose modules are all in the right places and whose error
    correction is computed over a different message — invisible until a reader
    tries it. The rows that catch it are the ones whose last nibble is not
    zero, so both versions appear at exactly full capacity as well as short.

  * **The mask numbering.** Micro QR's four masks are QR's numbers 1, 4, 6 and
    7, renumbered 0 to 3. A symbol masked with QR's pattern 2 and labelled 2
    scans as nonsense. The fixture sweeps until all four have been drawn, and
    the mask is compared rather than pinned: our automatic choice agrees with
    zint's on every payload tried, which is worth asserting while it holds.

  * **The format information.** Fifteen bits of BCH over five, XORed with
    0x4445 rather than QR's 0x5412. There are eight symbol numbers and four
    masks, and the sweep runs until every one of the thirty-two pairs has been
    drawn at least once.

  * **The padding.** 0xEC and 0x11 alternate, so a payload short enough to need
    two pad codewords is the only kind that says which comes first.

The fixture holds the modules as one row-major string of 0 and 1, without the
quiet zone, which is the encoder's business and is asserted separately.

Run with the decoder venv:  .decoders/bin/python tools/micro_qr_reference.py
"""

import csv
import pathlib
import random
import re
import string
import sys

try:
    from pyzint.zint import Zint, BARCODE_MICROQR
except ImportError:
    print("pyzint is missing; run: composer decoders:install", file=sys.stderr)
    raise SystemExit(1)

OUT = pathlib.Path(__file__).resolve().parent.parent / "tests/fixtures/micro_qr_reference.csv"

RECT = re.compile(r'<rect x="([\d.]+)" y="([\d.]+)" width="([\d.]+)" height="([\d.]+)" />')
SCALE = 2

# zint numbers the levels from one; M1's slot is the "L" one and means
# detection only, which is why it is spelled differently in the fixture.
ZINT_LEVEL = {"L": 1, "M": 2, "Q": 3}

# (version, level) -> the longest payload each mode holds, measured rather than
# transcribed: check() below re-derives every one of these from zint and fails
# if the table has drifted.
CAPACITY = {
    (1, None): {"numeric": 5},
    (2, "L"): {"numeric": 10, "alnum": 6},
    (2, "M"): {"numeric": 8, "alnum": 5},
    (3, "L"): {"numeric": 23, "alnum": 14, "byte": 9},
    (3, "M"): {"numeric": 18, "alnum": 11, "byte": 7},
    (4, "L"): {"numeric": 35, "alnum": 21, "byte": 15},
    (4, "M"): {"numeric": 30, "alnum": 18, "byte": 13},
    (4, "Q"): {"numeric": 21, "alnum": 13, "byte": 9},
}

ALNUM = string.digits + string.ascii_uppercase + " $%*+-./:"
BYTES = string.ascii_letters + string.digits + "!?,;()[]{}@#&_~^|<>=\"'`\\"


def draw(data: str, version: int, level: str | None) -> str:
    zz = Zint(
        data=data,
        kind=BARCODE_MICROQR,
        option_1=ZINT_LEVEL[level] if level else 1,
        option_2=version,
    )
    svg = zz.render_svg().decode()
    width, height = (int(v) for v in re.search(r'<svg width="(\d+)" height="(\d+)"', svg).groups())
    size = width // SCALE
    grid = [[0] * size for _ in range(height // SCALE)]
    for x, y, w, h in RECT.findall(svg):
        x, y, w, h = (int(float(v)) for v in (x, y, w, h))
        for row in range(y, y + h, SCALE):
            for column in range(x, x + w, SCALE):
                grid[row // SCALE][column // SCALE] = 1
    return "".join(str(v) for row in grid for v in row)


def fits(data: str, version: int, level: str | None) -> bool:
    try:
        draw(data, version, level)
        return True
    except RuntimeError:
        return False


def check_capacities() -> None:
    """The tables above, re-measured. A drifted number fails the run."""
    alphabets = {"numeric": "1", "alnum": "A", "byte": "a"}
    for (version, level), expected in CAPACITY.items():
        for mode, character in alphabets.items():
            longest = 0
            while longest < 40 and fits(character * (longest + 1), version, level):
                longest += 1
            assert longest == expected.get(mode, 0), (
                f"M{version}-{level or 'detect'} {mode}: zint holds {longest}, table says {expected.get(mode, 0)}"
            )


def run(alphabet: str, length: int, rng: random.Random) -> str:
    return "".join(rng.choice(alphabet) for _ in range(length))


def payloads(rng: random.Random) -> list[tuple[str, int, str | None]]:
    chosen: list[tuple[str, int, str | None]] = []

    for (version, level), capacity in CAPACITY.items():
        for mode, longest in capacity.items():
            alphabet = {"numeric": string.digits, "alnum": ALNUM, "byte": BYTES}[mode]

            # The two ends of every cell in the table: one character, and the
            # last one that fits. A capacity that is one too generous fails on
            # the second; one too mean never draws it at all.
            chosen.append((alphabet[0], version, level))
            chosen.append((run(alphabet, longest, rng), version, level))

            # A payload two codewords short of full, which is the only kind
            # that shows both pad codewords and the order they come in.
            if longest > 3:
                chosen.append((run(alphabet, longest - 3, rng), version, level))

            # Every remaining length, for the two versions that end on a
            # nibble: the nibble's value depends on where the bit stream stops,
            # so every stopping place is worth drawing.
            if version in (1, 3):
                for length in range(2, longest):
                    # Three payloads a length, not one: the nibble's *value*
                    # depends on the content and not only on where the stream
                    # stops, and a nibble that happens to come out zero is the
                    # one case that cannot tell a left-aligned nibble from a
                    # right-aligned one.
                    for _ in range(3):
                        chosen.append((run(alphabet, length, rng), version, level))

    # Real payloads, in the shapes Micro QR is actually used for: a part
    # number, a lot code, a serial, a short URL.
    chosen += [
        ("0123456789", 2, "L"),
        ("LOT4471", 3, "L"),
        ("SN-000123", 3, "L"),
        ("R47K 1%", 3, "M"),
        ("a.co/x8Kd", 4, "Q"),
        ("HTTP://A.CO/9F2", 4, "M"),
    ]

    # Payloads of the one shape the two encoders split differently: an
    # alphanumeric string with a long run of digits buried in it. zint takes
    # the digits out into a numeric segment, which ties or costs one bit more;
    # the search here leaves them where they are. These rows are in the fixture
    # on purpose, so that the branch of the test which handles a disagreement
    # is a branch that runs.
    chosen += [
        ("50/14241190B42C-B", 4, "M"),
        ("9/4C369919/2200", 4, "M"),
        ("CB9BAB2777-46017952A", 4, "L"),
        ("A941-61701532/CC", 4, "M"),
        ("6B//658B65141080B", 4, "M"),
    ]

    return chosen


def sweep(rng: random.Random, seen: set[tuple[int, int]]) -> list[tuple[str, int, str | None]]:
    """Rows drawn until every symbol number has appeared at every mask.

    Thirty-two pairs, and the mask is not something a payload can be chosen to
    produce — it falls out of scoring the four maskings of whatever the data
    happened to be. So this draws random payloads and keeps the ones that show
    a pair nothing has shown yet, which is the only way to be sure the format
    information is exercised across its whole range rather than at the handful
    of values that ordinary payloads happen to reach.
    """
    numbers = {
        (1, None): 0, (2, "L"): 1, (2, "M"): 2, (3, "L"): 3,
        (3, "M"): 4, (4, "L"): 5, (4, "M"): 6, (4, "Q"): 7,
    }
    kept: list[tuple[str, int, str | None]] = []

    for _ in range(4000):
        if len(seen) == 32:
            break
        version, level = rng.choice(list(numbers))
        mode = rng.choice([m for m in CAPACITY[(version, level)]])
        alphabet = {"numeric": string.digits, "alnum": ALNUM, "byte": BYTES}[mode]
        length = rng.randrange(1, CAPACITY[(version, level)][mode] + 1)
        data = run(alphabet, length, rng)

        modules = draw(data, version, level)
        pair = (numbers[(version, level)], read_mask(modules, version))
        if pair not in seen:
            seen.add(pair)
            kept.append((data, version, level))

    return kept


def read_mask(modules: str, version: int) -> int:
    """The mask out of the drawn symbol's own format information.

    Read back rather than assumed, so that the sweep above is steered by what
    zint drew and not by what this file believes the mask rule to be.
    """
    size = 11 + 2 * (version - 1)
    bits = "".join(modules[8 * size + column] for column in range(1, 9))
    bits += "".join(modules[row * size + 8] for row in range(7, 0, -1))
    value = (int(bits, 2) ^ 0b100_0100_0100_0101) >> 10
    return value & 0b11


MODE_BITS = {1: 0, 2: 1, 3: 2, 4: 3}
# Data bits by version and level, the terminator and padding included. Parsing
# has to stop here: past it lie the error correction codewords, and 0xEC read
# as a mode indicator is a perfectly plausible segment header.
DATA_BITS = {
    (1, None): 20, (2, "L"): 40, (2, "M"): 32, (3, "L"): 84,
    (3, "M"): 68, (4, "L"): 128, (4, "M"): 112, (4, "Q"): 80,
}
COUNT_BITS = {
    1: {"N": 3},
    2: {"N": 4, "A": 3},
    3: {"N": 5, "A": 4, "B": 4},
    4: {"N": 6, "A": 5, "B": 5},
}
MODE_VALUE = {0: "N", 1: "A", 2: "B", 3: "K"}
ALPHANUMERIC = string.digits + string.ascii_uppercase + " $%*+-./:"


def is_function(row: int, column: int) -> bool:
    return row == 0 or column == 0 or (row <= 8 and column <= 8)


def applies(mask: int, row: int, column: int) -> bool:
    if mask == 0:
        return row % 2 == 0
    if mask == 1:
        return (row // 2 + column // 3) % 2 == 0
    if mask == 2:
        return ((row * column) % 2 + (row * column) % 3) % 2 == 0
    return ((row + column) % 2 + (row * column) % 3) % 2 == 0


def data_bits(modules: str, version: int, mask: int) -> str:
    """The bit stream a drawn symbol carries: unmask, then follow the zigzag."""
    size = 11 + 2 * (version - 1)
    grid = [[int(modules[y * size + x]) for x in range(size)] for y in range(size)]
    for y in range(size):
        for x in range(size):
            if not is_function(y, x) and applies(mask, y, x):
                grid[y][x] ^= 1

    bits = []
    upwards = True
    for column in range(size - 1, 0, -2):
        rows = range(size - 1, -1, -1) if upwards else range(size)
        for row in rows:
            for target in (column, column - 1):
                if not is_function(row, target):
                    bits.append(str(grid[row][target]))
        upwards = not upwards

    return "".join(bits)


def read_segments(modules: str, version: int, level: str | None, mask: int) -> str:
    """How zint split the payload, out of the symbol it drew.

    Recorded because it is the one thing the two encoders legitimately
    disagree about. zint's mode selection is a heuristic that splits a run of
    digits out of an alphanumeric segment; the search in Encoding\\MicroQr\\
    Segments is a shortest path and is never longer. Over nine hundred random
    payloads they agree on all but eight, and on those eight zint's encoding
    ties four times and is one bit longer four times. So the test compares
    modules where the splits match and asserts the ordering where they do not,
    which is the strongest claim that is actually true.
    """
    bits = data_bits(modules, version, mask)[:DATA_BITS[(version, level)]]
    at = 0
    parts = []

    def take(count: int) -> int | None:
        nonlocal at
        if at + count > len(bits):
            return None
        value = int(bits[at:at + count], 2) if count else 0
        at += count
        return value

    while True:
        width = MODE_BITS[version]
        if at + width > len(bits):
            break
        value = take(width)
        if value is None:
            break
        letter = MODE_VALUE[value]
        if letter not in COUNT_BITS[version]:
            break

        count = take(COUNT_BITS[version][letter])
        if not count:
            break

        widths = {"N": (10, 3, {1: 4, 2: 7}), "A": (11, 2, {1: 6}), "B": (8, 1, {})}[letter]
        group, per, tail = widths
        if take(group * (count // per)) is None:
            break
        if count % per and take(tail[count % per]) is None:
            break

        parts.append(f"{letter}:{count}")

    return "|".join(parts)


def main() -> int:
    check_capacities()

    rng = random.Random(4)
    rows: dict[tuple[str, int, str | None], str] = {}

    numbers = {
        (1, None): 0, (2, "L"): 1, (2, "M"): 2, (3, "L"): 3,
        (3, "M"): 4, (4, "L"): 5, (4, "M"): 6, (4, "Q"): 7,
    }

    seen: set[tuple[int, int]] = set()
    for data, version, level in payloads(rng):
        modules = draw(data, version, level)
        rows[(data, version, level)] = modules
        seen.add((numbers[(version, level)], read_mask(modules, version)))

    for data, version, level in sweep(rng, seen):
        rows[(data, version, level)] = draw(data, version, level)

    if len(seen) != 32:
        print(f"only {len(seen)}/32 symbol-number and mask pairs drawn", file=sys.stderr)
        return 1

    with OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["data", "version", "ecc", "mask", "segments", "modules"])
        for (data, version, level), modules in sorted(rows.items(), key=lambda item: str(item[0])):
            mask = read_mask(modules, version)
            writer.writerow([
                data,
                f"M{version}",
                level or "detect",
                mask,
                read_segments(modules, version, level, mask),
                modules,
            ])

    masks = {read_mask(m, key[1]) for key, m in rows.items()}
    print(
        f"{len(rows)} reference symbols, {len(seen)}/32 symbol-number and mask pairs, "
        f"masks {sorted(masks)} -> {OUT}"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
