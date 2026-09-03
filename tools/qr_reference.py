#!/usr/bin/env python3
"""Regenerate the QR fixture from Nayuki's qrcodegen.

Writes tests/fixtures/qr_reference.csv.

This one was added late, and the reason is worth recording. QR is the oldest
code in this library, and its fixture predates the rule that every symbology is
compared against an encoder we did not write. What the file held was our own
output, frozen — a regression fixture, which catches a change but cannot catch
a mistake that was there from the start. Every other symbology had a real
oracle behind it; QR, the one with four backends and a C++ core, did not.

The payload list is the one that was already there: 443 URLs, the shape this
library is most used for, each at all four error correction levels.

**The mask is recorded, not compared.** Masking is where conforming encoders
legitimately disagree: ISO/IEC 18004 clause 7.8.3 says to score all eight and
take the lowest, but the rules — chiefly rule 3, the 1:1:3:1:1 pattern — are
read differently in practice and ties are ordinary. Over sixty random byte
payloads, zxing-cpp and qrcodegen produce the same modules eight times. All
eight maskings carry identical data and every one of them scans. So the fixture
carries the mask qrcodegen chose and the test pins ours to it, which leaves the
comparison covering everything the encoder actually decides: the version, the
mode and character count, the codewords, the error correction, the block
interleaving and the module placement.

That leaves two questions this file does not answer, and they have tests of
their own rather than being left to the reader:

  - Whether our *automatic* mask choice is sane — QrMaskOptionTest asserts it
    is always one of the eight, and the decoder round trip scans all eight.
  - Whether the other three backends agree with the one compared here —
    QrBackendAgreementTest compares them module for module against it, which
    is what carries this oracle through to the native core.

qrcodegen's boostecl is switched off: it would silently raise the error
correction level whenever the data still fit, changing the codewords while
leaving the version and size alone.

Run with the decoder venv:  .decoders/bin/python tools/qr_reference.py
"""

import csv
import pathlib
import sys

try:
    from qrcodegen import QrCode, QrSegment
except ImportError:
    sys.exit("qrcodegen is missing — run: composer decoders:install")

ROOT = pathlib.Path(__file__).resolve().parent.parent
FIXTURE = ROOT / "tests" / "fixtures" / "qr_reference.csv"
PAYLOADS = ROOT / "tools" / "qr_reference_payloads.txt"

LEVELS = {
    "L": QrCode.Ecc.LOW,
    "M": QrCode.Ecc.MEDIUM,
    "Q": QrCode.Ecc.QUARTILE,
    "H": QrCode.Ecc.HIGH,
}


def encode(payload: str, ecc) -> QrCode:
    # One byte segment, which is what this library's QR pipeline produces.
    # qrcodegen would otherwise segment a URL into alphanumeric and byte runs
    # and emit a smaller symbol we do not attempt — a difference in
    # segmentation rather than in encoding, and not what this is checking.
    segment = QrSegment.make_bytes(payload.encode("utf-8"))
    return QrCode.encode_segments([segment], ecc, boostecl=False)


def modules(qr: QrCode) -> str:
    size = qr.get_size()
    return "".join(
        "1" if qr.get_module(x, y) else "0"
        for y in range(size)
        for x in range(size)
    )


def main() -> int:
    payloads = [line for line in PAYLOADS.read_text().splitlines() if line]
    if not payloads:
        sys.exit(f"no payloads in {PAYLOADS}")

    rows = []
    for payload in payloads:
        for name, ecc in LEVELS.items():
            qr = encode(payload, ecc)
            rows.append(
                {
                    "url": payload,
                    "ecl": name,
                    "version": qr.get_version(),
                    "size": qr.get_size(),
                    "mask": qr.get_mask(),
                    "bits": modules(qr),
                }
            )

    with FIXTURE.open("w", newline="") as handle:
        writer = csv.DictWriter(
            handle, fieldnames=["url", "ecl", "version", "size", "mask", "bits"]
        )
        writer.writeheader()
        writer.writerows(rows)

    print(f"{FIXTURE.relative_to(ROOT)}: {len(rows)} rows from {len(payloads)} payloads")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
