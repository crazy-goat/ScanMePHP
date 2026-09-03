#!/usr/bin/env python3
"""Regenerate src/Encoding/MaxiCode/Placement.php from zxing-cpp.

MaxiCode's module map is the one part of the symbology that is a table rather
than a rule, and this measures it instead of transcribing it.

**The raster is useless here.** Every other fixture in this repository samples
``write_barcode_to_image`` at one pixel per module, and MaxiCode is the one
symbology where that assumption is false: the modules are hexagons on offset
rows, and three attempts at fitting the lattice to the raster disagreed with
each other across scales. ``write_barcode_to_svg`` states the answer exactly —
one hexagon subpath per dark module, plus three ``<circle>`` elements for the
bullseye, which is not made of modules at all and is why point-sampling the
centre gave alternating nonsense.

The lattice: 33 rows, 30 columns on even rows and 29 on odd, so 974 positions.
Hexagon centres sit at ``x = 1.5 + column`` on an even row and half a module
further right on an odd one, ``y = 1.58 + row * 0.86594``. Row parity is taken
from x rather than y — a whole offset is an even row, a half offset an odd one
— because x is exact where y accumulates rounding; cross-checked against y on
thousands of hexagons with no disagreement.

**How the placement is recovered.** Not by differential analysis, which only
reaches the data codewords: a change of payload moves the error correction too,
and one changed module cannot be attributed to one changed codeword. Instead
the whole 144-codeword vector is *computed* — data codewords from the code set
tables, then Reed-Solomon over GF(64) — and each of the 974 lattice positions is
matched to the (codeword, bit) whose value it tracks across a hundred payloads.
A position and a bit that agree on a hundred independent symbols are the same
thing. That match confirms the arithmetic and the placement together: if the
Galois field, the generator root, or the odd/even interleave of the secondary
message were wrong, nothing would line up at all. Three of the four candidate
interleavings match under 600 of the 813 varying positions; the right one
matches every single one.

Two things this cannot reach, and both are recorded rather than guessed:

  * **The mode codeword is constant** — every symbol here is mode 4 — so it has
    no signature to match. Its six cells are found by exhausting the primary
    message's error correction instead: corrupt five of the nineteen known
    primary codewords, which RS(20,10) still corrects, then flip one more cell.
    A cell that belongs to a codeword makes six errors and the symbol stops
    decoding; a cell that belongs to the orientation patterns is not read at
    all and the symbol still decodes. Exactly six cells fail, which is the
    check that the method is sound. Their bit order then comes from writing
    modes 2, 3 and 6 and asking the reader which mode it saw.

  * **Bits 3, 4 and 5 of the mode codeword** are zero in every mode the
    standard defines, so no symbol can distinguish them. The order used here is
    the one the regular 2x3 blocks use; it cannot be wrong in a way that shows.

Run with the decoder venv:  .decoders/bin/python tools/maxicode_placement.py
"""

import pathlib
import random
import re
import sys

try:
    import zxingcpp
except ImportError:  # pragma: no cover - developer tooling
    sys.exit("zxing-cpp is missing; run: composer decoders:install")

ROOT = pathlib.Path(__file__).resolve().parent.parent
OUT = ROOT / "src/Encoding/MaxiCode/Placement.php"

ROWS = 33
PITCH_Y = (29.29 - 1.58) / 32
CODEWORDS = 144
PRIMARY_DATA = 10
PRIMARY_CHECK = 10
SECONDARY_DATA = 84
SECONDARY_CHECK = 20  # per interleaved block, and there are two

# Code set A, measured byte by byte in a forced context; see CodeSets.php.
SET_A = {0x0D: 0, 0x20: 32}
SET_A.update({0x41 + i: 1 + i for i in range(26)})
SET_A.update({0x1C: 28, 0x1D: 29, 0x1E: 30})
SET_A.update({byte: 34 + byte - 0x22 for byte in range(0x22, 0x3B)})
PAD = 33
ALPHABET = bytes(sorted(SET_A))


# --- GF(64), the field MaxiCode's Reed-Solomon lives in ----------------------
PRIMITIVE = 0x43  # x^6 + x + 1
EXP = [0] * 64
LOG = [0] * 64
_x = 1
for _i in range(63):
    EXP[_i] = _x
    LOG[_x] = _i
    _x <<= 1
    if _x & 0x40:
        _x ^= PRIMITIVE


def _mul(a, b):
    return 0 if a == 0 or b == 0 else EXP[(LOG[a] + LOG[b]) % 63]


def _generator(count):
    poly = [1]
    for i in range(count):
        root = EXP[(1 + i) % 63]
        nxt = [0] * (len(poly) + 1)
        for j, c in enumerate(poly):
            nxt[j] ^= _mul(c, root)
            nxt[j + 1] ^= c
        poly = nxt
    return poly


def check_words(data, count):
    poly = _generator(count)
    remainder = [0] * count
    for word in data:
        factor = word ^ remainder[0]
        remainder = remainder[1:] + [0]
        if factor:
            for i in range(count):
                remainder[i] ^= _mul(factor, poly[count - 1 - i])
    return remainder


def data_codewords(payload: bytes):
    """The 93 data codewords, before the primary and secondary split."""
    data = [SET_A[b] for b in payload]

    return data + [PAD] * (93 - len(data))


def codeword_vector(payload: bytes):
    """The 144 codewords a mode 4 symbol for this set-A payload must hold."""
    data = data_codewords(payload)
    primary = [4] + data[:9]
    primary += check_words(primary, PRIMARY_CHECK)
    secondary = data[9:]

    return (
        primary
        + secondary
        + check_words(secondary[0::2], SECONDARY_CHECK)
        + check_words(secondary[1::2], SECONDARY_CHECK)
    )


# --- the oracle --------------------------------------------------------------
def columns(row: int) -> int:
    return 30 if row % 2 == 0 else 29


ALL = [(r, c) for r in range(ROWS) for c in range(columns(r))]


def dark_modules(payload) -> set:
    barcode = zxingcpp.create_barcode(payload, zxingcpp.BarcodeFormat.MaxiCode)
    path = re.search(r'<path d="([^"]+)"', zxingcpp.write_barcode_to_svg(barcode)).group(1)

    found = set()
    for subpath in (p for p in path.split("Z") if p.strip()):
        points = [(float(a), float(b)) for a, b in re.findall(r"[ML](-?[\d.]+) (-?[\d.]+)", subpath)]
        xs = [p[0] for p in points]
        ys = [p[1] for p in points]
        row = round(((min(ys) + max(ys)) / 2 - 1.58) / PITCH_Y)
        offset = (min(xs) + max(xs)) / 2 - 1.5
        odd = abs(offset - round(offset)) > 0.25
        found.add((row, round(offset - (0.5 if odd else 0))))

    return found


def main() -> int:
    random.seed(20260903)
    payloads = [bytes(random.choice(ALPHABET) for _ in range(93)) for _ in range(60)]
    payloads += [bytes(random.choice(ALPHABET) for _ in range(n)) for n in range(1, 94, 3)]

    darks = [dark_modules(p.decode("latin-1")) for p in payloads]
    vectors = [codeword_vector(p) for p in payloads]

    predicted = {}
    for word in range(CODEWORDS):
        for bit in range(6):
            key = tuple(bool(v[word] >> bit & 1) for v in vectors)
            predicted.setdefault(key, []).append((word, bit))

    observed = {}
    for position in ALL:
        observed.setdefault(tuple(position in d for d in darks), []).append(position)

    grid = {}
    constant = []
    for key, positions in observed.items():
        if len(set(key)) == 1:
            constant += positions
            continue
        candidates = predicted.get(key, [])
        if len(candidates) != 1 or len(positions) != 1:
            sys.exit(f"unresolved: {len(positions)} positions, {len(candidates)} candidates")
        grid[candidates[0]] = positions[0]

    # The mode codeword, which no payload varies. Found by exhausting the
    # primary message's error correction; see the module docstring.
    mode_cells = find_mode_cells(darks[0], data_codewords(payloads[0]), grid, constant)
    for bit, position in enumerate(mode_cells):
        grid[(0, bit)] = position

    missing = [(w, b) for w in range(CODEWORDS) for b in range(6) if (w, b) not in grid]
    if missing:
        sys.exit(f"{len(missing)} unplaced bits: {missing[:6]}")

    fixed = sorted(set(constant) - set(mode_cells) & darks[0])
    write_php(grid, fixed)
    print(f"{CODEWORDS} codewords, {len(fixed)} fixed dark modules -> {OUT}")

    return 0


def render(modules, scale=12):
    """The symbol as an image, so the reader can be used as an oracle.

    The bullseye is three circles rather than modules, which is exactly why the
    raster cannot be sampled to recover the lattice; drawing it here is the same
    fact from the other side.
    """
    from PIL import Image, ImageDraw

    image = Image.new("L", (32 * scale, 31 * scale), 255)
    draw = ImageDraw.Draw(image)
    for row, column in modules:
        x = 1.5 + column + (0.5 if row % 2 else 0.0)
        y = 1.58 + row * PITCH_Y
        draw.polygon(
            [((x + dx) * scale, (y + dy) * scale) for dx, dy in
             ((0, 0.5), (0.43, 0.25), (0.43, -0.25), (0, -0.5), (-0.43, -0.25), (-0.43, 0.25))],
            fill=0,
        )
    for radius in (4.108, 2.539, 0.97):
        draw.ellipse(
            [(15.5 - radius) * scale, (15.43 - radius) * scale,
             (15.5 + radius) * scale, (15.43 + radius) * scale],
            outline=0, width=max(1, round(0.785 * scale)),
        )

    return image


def read(modules):
    result = zxingcpp.read_barcode(render(modules), formats=zxingcpp.BarcodeFormat.MaxiCode)

    return result.extra.get("ECLevel") if result else None


def symbol(grid, fixed_dark, mode, mode_cells, data, corrupt=0):
    """A symbol with a chosen mode written into chosen cells."""
    primary = [mode] + data[:9]
    primary += check_words(primary, PRIMARY_CHECK)
    secondary = data[9:]
    vector = (primary + secondary
              + check_words(secondary[0::2], SECONDARY_CHECK)
              + check_words(secondary[1::2], SECONDARY_CHECK))

    modules = set(fixed_dark) | set(mode_cells)
    for (word, bit), position in grid.items():
        if word != 0 and vector[word] >> bit & 1:
            modules.add(position)
    for word in range(1, 1 + corrupt):
        for bit in range(6):
            modules ^= {grid[(word, bit)]}

    return modules


def find_mode_cells(dark, data, grid, constant):
    """The six cells of codeword 0, in bit order.

    Their locations come from the error correction budget: RS(20,10) corrects
    five wrong codewords and not six, so with five already spent, one more
    flipped module breaks the symbol if and only if the module belongs to a
    codeword. Their order comes from writing modes the reader can name.
    """
    exhausted = set(dark)
    for word in range(1, 6):
        for bit in range(6):
            exhausted ^= {grid[(word, bit)]}
    if read(exhausted) is None:
        sys.exit("five corrupted primary codewords should still correct")

    cells = [c for c in constant if read(exhausted ^ {c}) is None]
    if len(cells) != 6:
        sys.exit(f"expected six cells for the mode codeword, found {len(cells)}")

    # These are mode 4 symbols, so the one dark cell of the six is bit 2.
    ordered = [None] * 6
    ordered[2] = next(c for c in cells if c in dark)
    rest = [c for c in cells if c != ordered[2]]

    fixed_dark = sorted(set(constant) - set(cells) & dark)
    for mode, bit, companions in ((6, 1, [2]), (3, 0, [1])):
        for candidate in rest:
            written = [candidate] + [ordered[i] for i in companions]
            if read(symbol(grid, fixed_dark, mode, written, data, corrupt=5)) == str(mode):
                ordered[bit] = candidate
                break
        else:
            sys.exit(f"no cell writes mode {mode}")
        rest = [c for c in rest if c != ordered[bit]]

    # Bits 3, 4 and 5 are zero in every mode the standard defines, so nothing
    # can tell them apart. They take the order a regular 2x3 block uses.
    ordered[4], ordered[3], ordered[5] = sorted(rest)

    return ordered


def write_php(grid, fixed) -> None:
    rows = []
    for word in range(CODEWORDS):
        cells = ", ".join(f"[{grid[(word, bit)][0]}, {grid[(word, bit)][1]}]" for bit in range(6))
        rows.append(f"        {word} => [{cells}],")
    body = "\n".join(rows)
    dark = "\n".join(f"        [{r}, {c}]," for r, c in fixed)

    OUT.write_text(TEMPLATE.format(body=body, dark=dark))


TEMPLATE = '''<?php

declare(strict_types=1);

namespace CrazyGoat\\ScanMePHP\\Encoding\\MaxiCode;

/**
 * Where each of MaxiCode's 144 codewords writes its six bits.
 *
 * Generated by tools/maxicode_placement.py, which measures it against
 * zxing-cpp rather than transcribing ISO/IEC 16023. Do not edit by hand.
 */
final class Placement
{{
    /**
     * Row and column of each bit of each codeword, bit 0 first.
     *
     * @var array<int, list<array{{int, int}}>>
     */
    private const CELLS = [
{body}
    ];

    /**
     * Modules that are dark in every symbol: the orientation patterns.
     *
     * @var list<array{{int, int}}>
     */
    private const FIXED_DARK = [
{dark}
    ];

    /** @return list<array{{int, int}}> */
    public static function cells(int $codeword): array
    {{
        return self::CELLS[$codeword];
    }}

    /** @return list<array{{int, int}}> */
    public static function fixedDark(): array
    {{
        return self::FIXED_DARK;
    }}
}}
'''


if __name__ == "__main__":
    sys.exit(main())
