#!/usr/bin/env python3
"""What the four-state postal reference fixtures are drawn with.

Every other symbology here is verified against zxing-cpp, which both writes and
reads it. No four-state code has a free reader at all — zbar does not carry
them and the ones that do are commercial — so this tier is checked against an
encoder only, and that encoder is zint through `pyzint`.

Two things about that arrangement have to be said out loud wherever it is used,
because neither is visible from the fixture it produces:

  * **zint is not a second opinion about zxing.** zxing-cpp's writer *is* zint;
    the error text it raises on a malformed DataBar payload is zint's own
    return code. Reaching for `pyzint` where zxing has no reader is the same
    engine through another door. It is still an encoder we did not write, which
    is all a reference fixture requires — but it is one check rather than two,
    and the round trip that would have been the second one does not exist here.

  * **The states are recovered from geometry, not reported.** zint renders a
    four-state symbol as SVG rectangles and says nothing about which state each
    bar is. What it does say is where the rectangle starts and how tall it is,
    and at scale 1 those take exactly four values — the table below. So the
    reading of a symbol is a measurement of the drawing, and `--self-check`
    is what keeps it honest: zint's own DAFT symbology takes a state string
    verbatim, which makes it a fixture for this file's only real claim.

The state letters are DAFT's: **D** descender, **A** ascender, **F** full,
**T** tracker. Using zint's own alphabet is what lets the self-check exist.

Run with the decoder venv:  .decoders/bin/python tools/four_state.py --self-check
"""

import re
import sys

try:
    from pyzint import Barcode
except ImportError:
    print("pyzint is missing; run: composer decoders:install", file=sys.stderr)
    raise SystemExit(1)

# (y, height) at scale 1, measured against DAFT. The symbol is sixteen units
# tall and splits 6/4/6: a tracker is the middle four, an ascender is the top
# ten, a descender the bottom ten, a full bar all sixteen. That ratio is the
# whole meaning of the symbology, and it is the one the renderers already scale
# proportionally -- see AbstractRenderOptions::resolveRowHeights().
STATES = {
    (0.0, 10.0): "A",
    (0.0, 16.0): "F",
    (6.0, 10.0): "D",
    (6.0, 4.0): "T",
}

# Bar pitch and bar width at scale 1. Asserted rather than assumed: a zint that
# ever draws them differently would still produce a plausible state string,
# with bars silently merged or dropped.
PITCH = 4.0
BAR_WIDTH = 2.0

RECT = re.compile(r"<rect[^>]*/>")
ATTR = re.compile(r'(\w+)="([^"]+)"')


def states(symbology: Barcode, data: str) -> str:
    """The symbol as a string of D, A, F and T, left to right.

    :raises RuntimeError: from zint, when it refuses the payload
    :raises AssertionError: when the drawing is not the geometry measured above
    """
    svg = symbology(data, scale=1).render_svg().decode()

    # The first rectangle is the white background, not a bar.
    bars = [dict(ATTR.findall(rect)) for rect in RECT.findall(svg)][1:]
    placed = sorted(
        (float(bar["x"]), float(bar["y"]), float(bar["width"]), float(bar["height"]))
        for bar in bars
    )

    drawn = []
    for index, (x, y, width, height) in enumerate(placed):
        assert width == BAR_WIDTH, f"{data}: bar {index} is {width} wide"
        assert x == index * PITCH, f"{data}: bar {index} sits at {x}, not {index * PITCH}"

        state = STATES.get((y, height))
        assert state is not None, f"{data}: bar {index} is ({y}, {height}), which is no state"
        drawn.append(state)

    return "".join(drawn)


def self_check() -> int:
    """Drive every ordered pair of states through zint and read them back.

    Pairs rather than single letters because the failure worth catching is not
    a mislabelled state -- it is two adjacent bars whose rectangles overlap
    being read as one, which needs a neighbour to happen at all.
    """
    sequence = "".join(a + b for a in "DAFT" for b in "DAFT")
    read = states(Barcode.DAFT, sequence)

    assert read == sequence, f"DAFT round trip: wrote {sequence}, read {read}"
    assert len(set(read)) == 4, "the self-check never exercised all four states"

    # Each of the four codes this file exists for, at a payload their standards
    # publish, so that a zint which stops accepting one of them fails here
    # rather than in the middle of regenerating a fixture.
    for symbology, payload in (
        (Barcode.RM4SCC, "LE28HS"),
        (Barcode.KIX, "2500GG30250"),
        (Barcode.ONECODE, "01234567094987654321"),
        (Barcode.AUSPOST, "96201234"),
    ):
        drawn = states(symbology, payload)
        assert set(drawn) <= set("DAFT"), f"{symbology.name}: unknown state"
        print(f"{symbology.name:8} {payload:22} {len(drawn):3} bars  {drawn}")

    print(f"\nself-check ok: {len(sequence)} bars, every ordered pair of states")
    return 0


if __name__ == "__main__":
    if "--self-check" not in sys.argv:
        print(__doc__.strip().splitlines()[0], file=sys.stderr)
        print("nothing to do; pass --self-check", file=sys.stderr)
        raise SystemExit(2)
    sys.exit(self_check())
