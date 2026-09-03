#!/usr/bin/env python3
"""Regenerate tests/fixtures/rmqr_reference.csv from zint.

rMQR is in a worse verification position than Micro QR and a better one than
the four-state postal codes, and it is worth being exact about which.

There is an encoder here we did not write -- zint, reached through pyzint, with
the size and the error correction level pinned rather than left to its own
policy -- so every module of every symbol in this file is somebody else's
opinion. There is no *reader*: zxing-cpp 3.1.1 lists RMQRCode among its formats
and cannot decode one, not ours and not zint's own, so DecoderRoundTripTest
exempts the symbology and gates the exemption on that staying true. When a
reader appears, the exemption fails and the round trip goes in.

That missing second opinion is why this fixture is exhaustive rather than
representative. Every one of the sixty-four cells -- thirty-two sizes times two
levels -- is drawn in all three modes, at one character, at its longest, and at
a length in between, which is where the things that go wrong actually go wrong:

  * **The count indicator widths.** Thirty-two sizes times three modes is
    ninety-six numbers and they are not "wide enough for the capacity": R7x43
    holds seven alphanumeric characters and spends four bits saying so, and
    R11x27 holds fourteen digits and spends five. A wrong width shifts every
    bit after it, so each cell is drawn at a length that needs the top bit of
    its count and at one that does not.

  * **The block interleaving.** Half the cells split their data into two to six
    Reed-Solomon blocks, and the count is not derivable: R15x99-H splits
    forty-eight data codewords into four blocks where R13x139-M splits a
    hundred and six into three. Interleaving is invisible in a one-block cell
    and destroys every codeword in a multi-block one, so the longest payload in
    each cell -- the only length where every block is full -- is always drawn.

  * **The two format copies.** Eighteen bits of BCH over six, written twice and
    masked with *different* constants, 0x1FAB2 and 0x20A7B. Using one mask for
    both copies draws a symbol whose first copy is right, which is exactly the
    kind of mistake a fixture of a few sizes would miss. All sixty-four format
    values appear here because all sixty-four cells do.

  * **The corner patterns.** The top-right and bottom-left corners each carry
    three modules the alternating timing pattern would otherwise get wrong, and
    they are the only thing distinguishing the two ends of a seven-module-tall
    rectangle. Every size draws them.

  * **The padding.** 0xEC and 0x11 alternate, so a payload short enough to need
    two pad codewords is the only kind that says which comes first.

The fixture holds the modules as one row-major string of 0 and 1, without the
quiet zone, which is the encoder's business and is asserted separately.

Run with the decoder venv:  .decoders/bin/python tools/rmqr_reference.py
"""

import csv
import pathlib
import random
import re
import string
import sys

try:
    from pyzint.zint import Zint, BARCODE_RMQR
except ImportError:
    print("pyzint is missing; run: composer decoders:install", file=sys.stderr)
    raise SystemExit(1)

OUT = pathlib.Path(__file__).resolve().parent.parent / "tests/fixtures/rmqr_reference.csv"

RECT = re.compile(r'<rect x="([\d.]+)" y="([\d.]+)" width="([\d.]+)" height="([\d.]+)" />')
SCALE = 2

# zint numbers the levels from one and rMQR uses only two of the four slots.
ZINT_LEVEL = {"M": 2, "H": 4}

# Height and width of each size, in the order the format information numbers
# them. The index is the number the symbol carries, so this order is fixed.
SIZES = [
    (7, 43), (7, 59), (7, 77), (7, 99), (7, 139),
    (9, 43), (9, 59), (9, 77), (9, 99), (9, 139),
    (11, 27), (11, 43), (11, 59), (11, 77), (11, 99), (11, 139),
    (13, 27), (13, 43), (13, 59), (13, 77), (13, 99), (13, 139),
    (15, 43), (15, 59), (15, 77), (15, 99), (15, 139),
    (17, 43), (17, 59), (17, 77), (17, 99), (17, 139),
]
NAMES = [f"R{h}x{w}" for h, w in SIZES]
INDEX = {name: i for i, name in enumerate(NAMES)}

# (size, level) -> the longest payload each mode holds, measured rather than
# transcribed: check_capacities() re-derives every one of these from zint and
# fails if the table has drifted.
CAPACITY = {
    ("R7x43", "M"): {"numeric": 12, "alnum": 7, "byte": 5},
    ("R7x43", "H"): {"numeric": 5, "alnum": 3, "byte": 2},
    ("R7x59", "M"): {"numeric": 26, "alnum": 16, "byte": 11},
    ("R7x59", "H"): {"numeric": 14, "alnum": 8, "byte": 6},
    ("R7x77", "M"): {"numeric": 45, "alnum": 27, "byte": 19},
    ("R7x77", "H"): {"numeric": 21, "alnum": 13, "byte": 9},
    ("R7x99", "M"): {"numeric": 64, "alnum": 39, "byte": 27},
    ("R7x99", "H"): {"numeric": 30, "alnum": 18, "byte": 13},
    ("R7x139", "M"): {"numeric": 102, "alnum": 62, "byte": 42},
    ("R7x139", "H"): {"numeric": 54, "alnum": 33, "byte": 22},
    ("R9x43", "M"): {"numeric": 26, "alnum": 16, "byte": 11},
    ("R9x43", "H"): {"numeric": 14, "alnum": 8, "byte": 6},
    ("R9x59", "M"): {"numeric": 47, "alnum": 29, "byte": 20},
    ("R9x59", "H"): {"numeric": 23, "alnum": 14, "byte": 10},
    ("R9x77", "M"): {"numeric": 71, "alnum": 43, "byte": 30},
    ("R9x77", "H"): {"numeric": 37, "alnum": 23, "byte": 16},
    ("R9x99", "M"): {"numeric": 97, "alnum": 59, "byte": 40},
    ("R9x99", "H"): {"numeric": 49, "alnum": 30, "byte": 20},
    ("R9x139", "M"): {"numeric": 147, "alnum": 89, "byte": 61},
    ("R9x139", "H"): {"numeric": 75, "alnum": 46, "byte": 31},
    ("R11x27", "M"): {"numeric": 14, "alnum": 8, "byte": 6},
    ("R11x27", "H"): {"numeric": 9, "alnum": 6, "byte": 4},
    ("R11x43", "M"): {"numeric": 42, "alnum": 26, "byte": 18},
    ("R11x43", "H"): {"numeric": 23, "alnum": 14, "byte": 10},
    ("R11x59", "M"): {"numeric": 71, "alnum": 43, "byte": 30},
    ("R11x59", "H"): {"numeric": 33, "alnum": 20, "byte": 14},
    ("R11x77", "M"): {"numeric": 100, "alnum": 60, "byte": 41},
    ("R11x77", "H"): {"numeric": 52, "alnum": 31, "byte": 21},
    ("R11x99", "M"): {"numeric": 133, "alnum": 81, "byte": 55},
    ("R11x99", "H"): {"numeric": 66, "alnum": 40, "byte": 27},
    ("R11x139", "M"): {"numeric": 198, "alnum": 120, "byte": 82},
    ("R11x139", "H"): {"numeric": 97, "alnum": 59, "byte": 40},
    ("R13x27", "M"): {"numeric": 26, "alnum": 16, "byte": 11},
    ("R13x27", "H"): {"numeric": 14, "alnum": 8, "byte": 6},
    ("R13x43", "M"): {"numeric": 61, "alnum": 37, "byte": 26},
    ("R13x43", "H"): {"numeric": 28, "alnum": 17, "byte": 12},
    ("R13x59", "M"): {"numeric": 88, "alnum": 53, "byte": 36},
    ("R13x59", "H"): {"numeric": 45, "alnum": 27, "byte": 18},
    ("R13x77", "M"): {"numeric": 123, "alnum": 75, "byte": 51},
    ("R13x77", "H"): {"numeric": 66, "alnum": 40, "byte": 27},
    ("R13x99", "M"): {"numeric": 171, "alnum": 104, "byte": 71},
    ("R13x99", "H"): {"numeric": 80, "alnum": 49, "byte": 33},
    ("R13x139", "M"): {"numeric": 251, "alnum": 152, "byte": 104},
    ("R13x139", "H"): {"numeric": 126, "alnum": 76, "byte": 52},
    ("R15x43", "M"): {"numeric": 76, "alnum": 46, "byte": 31},
    ("R15x43", "H"): {"numeric": 33, "alnum": 20, "byte": 13},
    ("R15x59", "M"): {"numeric": 112, "alnum": 68, "byte": 46},
    ("R15x59", "H"): {"numeric": 59, "alnum": 36, "byte": 24},
    ("R15x77", "M"): {"numeric": 157, "alnum": 95, "byte": 65},
    ("R15x77", "H"): {"numeric": 71, "alnum": 43, "byte": 29},
    ("R15x99", "M"): {"numeric": 207, "alnum": 126, "byte": 86},
    ("R15x99", "H"): {"numeric": 111, "alnum": 68, "byte": 46},
    ("R15x139", "M"): {"numeric": 301, "alnum": 182, "byte": 125},
    ("R15x139", "H"): {"numeric": 162, "alnum": 98, "byte": 67},
    ("R17x43", "M"): {"numeric": 90, "alnum": 55, "byte": 37},
    ("R17x43", "H"): {"numeric": 47, "alnum": 28, "byte": 19},
    ("R17x59", "M"): {"numeric": 131, "alnum": 79, "byte": 54},
    ("R17x59", "H"): {"numeric": 63, "alnum": 38, "byte": 26},
    ("R17x77", "M"): {"numeric": 183, "alnum": 111, "byte": 76},
    ("R17x77", "H"): {"numeric": 87, "alnum": 53, "byte": 36},
    ("R17x99", "M"): {"numeric": 236, "alnum": 143, "byte": 98},
    ("R17x99", "H"): {"numeric": 131, "alnum": 79, "byte": 54},
    ("R17x139", "M"): {"numeric": 361, "alnum": 219, "byte": 150},
    ("R17x139", "H"): {"numeric": 178, "alnum": 108, "byte": 74},
}

# Data codewords, by size index, at M and at H. Only used to read zint's own
# segmentation back out of the modules it drew.
DATA_CODEWORDS = {
    "M": [6, 12, 20, 28, 44, 12, 21, 31, 42, 63, 7, 19, 31, 43, 57, 84,
          12, 27, 38, 53, 73, 106, 33, 48, 67, 88, 127, 39, 56, 78, 100, 152],
    "H": [3, 7, 10, 14, 24, 7, 11, 17, 22, 33, 5, 11, 15, 23, 29, 42,
          7, 13, 20, 29, 35, 54, 15, 26, 31, 48, 69, 21, 28, 38, 56, 76],
}
BLOCKS = {
    "M": [1, 1, 1, 1, 1, 1, 1, 1, 1, 2, 1, 1, 1, 1, 2, 2,
          1, 1, 1, 2, 2, 3, 1, 1, 2, 2, 3, 1, 2, 2, 3, 4],
    "H": [1, 1, 1, 1, 2, 1, 1, 2, 2, 3, 1, 1, 2, 2, 2, 3,
          1, 1, 2, 2, 3, 4, 2, 2, 3, 4, 5, 2, 2, 3, 4, 6],
}
COUNT_BITS = {
    "numeric": [4, 5, 6, 7, 7, 5, 6, 7, 7, 8, 5, 6, 7, 7, 8, 8,
                5, 7, 7, 8, 8, 8, 7, 7, 8, 8, 9, 7, 8, 8, 8, 9],
    "alnum": [4, 5, 5, 6, 6, 5, 5, 6, 6, 7, 4, 5, 6, 6, 7, 7,
              5, 6, 6, 7, 7, 8, 6, 7, 7, 7, 8, 6, 7, 7, 8, 8],
    "byte": [3, 4, 5, 5, 6, 4, 5, 5, 6, 6, 3, 5, 5, 6, 6, 7,
             4, 5, 6, 6, 7, 7, 6, 6, 7, 7, 7, 6, 6, 7, 7, 8],
}
ALIGNMENT = {27: [], 43: [21], 59: [19, 39], 77: [25, 51],
             99: [23, 49, 75], 139: [27, 55, 83, 111]}

ALNUM = string.digits + string.ascii_uppercase + " $%*+-./:"
BYTES = string.ascii_letters + string.digits + "!?,;()[]{}@#&_~^|<>=\"'`\\"


def draw(data: str, size: str, level: str) -> str:
    zz = Zint(
        data=data,
        kind=BARCODE_RMQR,
        option_1=ZINT_LEVEL[level],
        option_2=INDEX[size] + 1,
    )
    svg = zz.render_svg().decode()
    width, height = (int(v) for v in re.search(r'<svg width="(\d+)" height="(\d+)"', svg).groups())
    columns, rows = width // SCALE, height // SCALE
    grid = [[0] * columns for _ in range(rows)]
    for x, y, w, h in RECT.findall(svg):
        x, y, w, h = (int(float(v)) for v in (x, y, w, h))
        for row in range(y, y + h, SCALE):
            for column in range(x, x + w, SCALE):
                grid[row // SCALE][column // SCALE] = 1
    return "".join(str(v) for row in grid for v in row)


def fits(data: str, size: str, level: str) -> bool:
    try:
        draw(data, size, level)
        return True
    except RuntimeError:
        return False


def check_capacities() -> None:
    """The table above, re-measured. A drifted number fails the run."""
    alphabets = {"numeric": "1", "alnum": "A", "byte": "a"}
    for (size, level), expected in CAPACITY.items():
        for mode, character in alphabets.items():
            want = expected[mode]
            assert fits(character * want, size, level), \
                f"{size}-{level} {mode}: zint refuses {want}, table says it holds them"
            assert not fits(character * (want + 1), size, level), \
                f"{size}-{level} {mode}: zint holds more than the table's {want}"


def run(alphabet: str, length: int, rng: random.Random) -> str:
    return "".join(rng.choice(alphabet) for _ in range(length))


def payloads(rng: random.Random) -> list[tuple[str, str, str]]:
    """Every cell, in every mode, at the lengths where encodings break."""
    rows: list[tuple[str, str, str]] = []
    alphabets = {"numeric": string.digits, "alnum": ALNUM, "byte": BYTES}

    for (size, level), capacity in CAPACITY.items():
        for mode, alphabet in alphabets.items():
            longest = capacity[mode]
            # One character exercises the shortest header and the deepest
            # padding; the longest fills every Reed-Solomon block; the middle
            # one is where the count indicator's top bit changes.
            for length in {1, longest, max(1, longest - 3), max(1, longest // 2)}:
                rows.append((run(alphabet, length, rng), size, level))

    # Payloads whose cheapest encoding is not one segment, which is the only
    # thing in this file our encoder is allowed to disagree with zint about.
    for data in ("LOT4471", "SN-000123", "R47K 1%", "a.co/x8Kd", "HTTP://A.CO/9F2",
                 "PN12345-REV/C", "2026-09-03T12:00", "ABC123def456"):
        for size, level in (("R7x139", "M"), ("R11x77", "M"), ("R13x99", "H"), ("R17x139", "M")):
            if fits(data, size, level):
                rows.append((data, size, level))

    return rows


def is_function(row: int, column: int, height: int, width: int) -> bool:
    """Everything a codeword bit cannot be written to."""
    if row in (0, height - 1) or column in (0, width - 1):
        return True
    if row < 7 and column < 8:
        return True
    if height > 7 and row == 7 and column < 8:
        return True
    if row >= height - 5 and column >= width - 5:
        return True
    for centre in ALIGNMENT[width]:
        if column == centre:
            return True
        if abs(column - centre) == 1 and (row < 3 or row >= height - 3):
            return True
    if row == 1 and column == width - 2:
        return True
    if row == height - 2 and column == 1:
        return True
    # The two format copies.
    if 1 <= row <= 5 and (column in (8, 9, 10) or (column == 11 and row <= 3)):
        return True
    if height - 6 <= row <= height - 2 and column in (width - 8, width - 7, width - 6):
        return True
    if row == height - 6 and column in (width - 5, width - 4, width - 3):
        return True
    return False


def positions(height: int, width: int) -> list[tuple[int, int]]:
    """QR's zigzag on a rectangle, in the order the codeword bits are written."""
    out: list[tuple[int, int]] = []
    upward = True
    right = width - 1
    while right > 0:
        rows = range(height - 1, -1, -1) if upward else range(height)
        for row in rows:
            for column in (right, right - 1):
                if not is_function(row, column, height, width):
                    out.append((row, column))
        upward = not upward
        right -= 2
    return out


def data_codewords(modules: str, size: str, level: str) -> list[int]:
    """zint's own data codewords, de-masked and de-interleaved."""
    index = INDEX[size]
    height, width = SIZES[index]
    bits = []
    for row, column in positions(height, width):
        value = int(modules[row * width + column])
        bits.append(value ^ (1 if (row // 2 + column // 3) % 2 == 0 else 0))

    stream = [int("".join(str(b) for b in bits[i:i + 8]), 2) for i in range(0, len(bits) - 7, 8)]

    wanted = DATA_CODEWORDS[level][index]
    blocks = BLOCKS[level][index]
    short, long = divmod(wanted, blocks)
    sizes = [short + (1 if b >= blocks - long else 0) for b in range(blocks)]

    chunks: list[list[int]] = [[] for _ in range(blocks)]
    cursor = 0
    for position in range(max(sizes)):
        for block in range(blocks):
            if position < sizes[block]:
                chunks[block].append(stream[cursor])
                cursor += 1

    return [codeword for chunk in chunks for codeword in chunk]


def read_segments(modules: str, size: str, level: str) -> str:
    """The mode split zint chose, spelled 'N:4|A:3', read back out of its bits."""
    index = INDEX[size]
    codewords = data_codewords(modules, size, level)
    bits = "".join(format(c, "08b") for c in codewords)
    cursor = 0

    def take(count: int) -> int | None:
        nonlocal cursor
        if count == 0:
            return 0
        if cursor + count > len(bits):
            return None
        value = int(bits[cursor:cursor + count], 2)
        cursor += count
        return value

    widths = {
        1: ("N", "numeric", (10, 3, {1: 4, 2: 7})),
        2: ("A", "alnum", (11, 2, {1: 6})),
        3: ("B", "byte", (8, 1, {})),
    }

    parts = []
    while True:
        mode = take(3)
        if mode is None or mode not in widths:
            break
        letter, key, (group, per, tail) = widths[mode]
        count = take(COUNT_BITS[key][index])
        if count is None:
            break
        if take(group * (count // per)) is None:
            break
        if count % per and take(tail[count % per]) is None:
            break
        parts.append(f"{letter}:{count}")

    return "|".join(parts)


def main() -> int:
    check_capacities()

    rng = random.Random(6)
    rows: dict[tuple[str, str, str], str] = {}

    for data, size, level in payloads(rng):
        rows[(data, size, level)] = draw(data, size, level)

    cells = {(size, level) for _, size, level in rows}
    if len(cells) != len(CAPACITY):
        print(f"only {len(cells)}/{len(CAPACITY)} cells drawn", file=sys.stderr)
        return 1

    with OUT.open("w", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["data", "size", "ecc", "segments", "modules"])
        for (data, size, level), modules in sorted(rows.items(), key=lambda item: str(item[0])):
            writer.writerow([data, size, level, read_segments(modules, size, level), modules])

    print(f"{len(rows)} reference symbols over {len(cells)} cells -> {OUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
