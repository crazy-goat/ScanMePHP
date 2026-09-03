#!/usr/bin/env python3
"""Regenerate the GS1 QR fixture from Nayuki's qrcodegen.

Writes tests/fixtures/gs1_qr_reference.csv.

Two things about this one are different from the other reference tools, and
both are worth stating rather than discovering later.

**The oracle is qrcodegen, not zxing-cpp.** zxing-cpp writes a GS1 QR happily,
but it chooses its own mode segmentation — it will split a payload into a
numeric run and a byte run where we encode the whole thing as bytes. That is a
legitimate choice on its part and a symbol we do not attempt, so the two stop
being comparable for any payload containing a digit run. qrcodegen lets the
segments be handed to it directly, so the comparison stays about the encoding
rather than about whose segmenter is cleverer.

**The mask is recorded, not compared.** Masking is where conforming encoders
legitimately disagree: the penalty rules in ISO/IEC 18004 clause 7.8.3 are read
differently in practice — chiefly rule 3, the 1:1:3:1:1 pattern — and ties are
common. Over sixty random byte payloads, zxing-cpp and qrcodegen produce the
same modules eight times; ours matches zxing on twenty-nine and qrcodegen on
twenty-two. All of those symbols scan and carry identical data. So the fixture
carries the mask qrcodegen chose, and the test pins our encoding *at that
mask*. What that comparison still covers is everything the encoder decides:
the version, the FNC1 indicator, the codewords, the error correction, the
interleaving and the module placement. What it deliberately does not cover is
which of eight equally valid symbols to emit, and tests/Gs1QrTest.php declares
that boundary so it cannot quietly become an excuse.

qrcodegen ships no FNC1 helper, so the four bits are supplied here as a raw
segment. That is the whole definition of FNC1 in first position — mode
indicator 0101, no character count, no data (ISO/IEC 18004:2015 Table 2) — and
everything downstream of it is qrcodegen's own.

Run with the decoder venv:  .decoders/bin/python tools/gs1_qr_reference.py
"""

import csv
import pathlib
import sys

try:
    from qrcodegen import QrCode, QrSegment
except ImportError:
    sys.exit("qrcodegen is missing — run: composer decoders:install")

ROOT = pathlib.Path(__file__).resolve().parent.parent
FIXTURE = ROOT / "tests" / "fixtures" / "gs1_qr_reference.csv"

# Mode indicator 0101, no character count field at any version, no data.
FNC1_FIRST_POSITION = QrSegment.Mode(0b0101, (0, 0, 0))

LEVELS = {
    "L": QrCode.Ecc.LOW,
    "M": QrCode.Ecc.MEDIUM,
    "Q": QrCode.Ecc.QUARTILE,
    "H": QrCode.Ecc.HIGH,
}

# Element strings paired with the payload a scanner reports for them. The
# payload is spelled out rather than derived so that a mistake in our own
# separator placement cannot travel into the fixture it is checked against.
CASES = [
    ("(10)abcdefgh", "10abcdefgh"),
    ("(21)abcdefghij", "21abcdefghij"),
    ("(01)09501101020917", "0109501101020917"),
    ("(01)09501101020917(10)LOT0001", "010950110102091710LOT0001"),
    ("(01)09501101020917(17)123100(10)ABC123", "01095011010209171712310010ABC123"),
    # (10) is variable length, so a separator follows it; (17) is on the
    # predefined-length list and still needs one. Both are in here on purpose.
    ("(10)LOT1(21)SER1", "10LOT1\x1d21SER1"),
    ("(17)123100(10)LOT1", "1712310010LOT1"),
    ("(00)123456789012345678", "00123456789012345678"),
    ("(410)9501101020917(422)056", "4109501101020917422056"),
    # Long enough to need a wider character count field and more than one
    # error correction block.
    ("(10)" + "a" * 20 + "(21)" + "b" * 20, "10" + "a" * 20 + "\x1d" + "21" + "b" * 20),
    ("(240)" + "x" * 30, "240" + "x" * 30),
]


def encode(payload: str, ecc) -> QrCode:
    segments = [
        QrSegment(FNC1_FIRST_POSITION, 0, []),
        QrSegment.make_bytes(payload.encode("utf-8")),
    ]
    # boostecl would silently raise the error correction level whenever the
    # data still fits at a higher one, which changes the codewords while
    # leaving the version and size alone — a fixture that looked right and
    # compared against a symbol nobody asked for.
    return QrCode.encode_segments(segments, ecc, boostecl=False)


def modules(qr: QrCode) -> str:
    size = qr.get_size()
    return "".join(
        "1" if qr.get_module(x, y) else "0"
        for y in range(size)
        for x in range(size)
    )


def main() -> int:
    rows = []
    for elements, payload in CASES:
        for name, ecc in LEVELS.items():
            qr = encode(payload, ecc)
            rows.append(
                {
                    "elements": elements,
                    "ecl": name,
                    "version": qr.get_version(),
                    "size": qr.get_size(),
                    "mask": qr.get_mask(),
                    "modules": modules(qr),
                }
            )

    with FIXTURE.open("w", newline="") as handle:
        writer = csv.DictWriter(
            handle, fieldnames=["elements", "ecl", "version", "size", "mask", "modules"]
        )
        writer.writeheader()
        writer.writerows(rows)

    print(f"{FIXTURE.relative_to(ROOT)}: {len(rows)} rows")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
