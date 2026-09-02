#!/usr/bin/env python3
"""Decode barcode images and report what a real scanner sees.

The PHP test suite verifies its own encoders against its own tables, which
cannot catch a table that is wrong in the same direction as the test. This
script is the external opinion: it shells out to zxing-cpp, the reference
decoder, and reports the format and payload it recovers.

Usage:  decode.py [--formats NAME[,NAME...]] <image> [<image> ...]
Output: one JSON object per line, in argument order.

--formats narrows what the decoder is willing to see. It exists because some
symbologies are the same bars as another: a UPC-A symbol is an EAN-13 with a
leading zero, and with every format enabled zxing-cpp reports the EAN-13
reading. Asking for UPC-A is not making the test easier — the bars still have
to decode — it is asking the question the caller asked.
"""
import json
import sys

try:
    import zxingcpp
    from PIL import Image
except ImportError as exc:  # pragma: no cover - reported to the caller as data
    print(json.dumps({"error": f"decoder unavailable: {exc}"}))
    sys.exit(2)


def decode(path: str, formats) -> dict:
    try:
        image = Image.open(path).convert("L")
    except Exception as exc:
        return {"file": path, "error": f"cannot read image: {exc}"}

    results = zxingcpp.read_barcodes(image) if formats is None \
        else zxingcpp.read_barcodes(image, formats=formats)
    return {
        "file": path,
        "symbols": [
            {
                "format": str(r.format),
                "text": r.text,
                "bytes": list(r.bytes),
                "orientation": r.orientation,
                "valid": r.valid,
            }
            for r in results
        ],
    }


def main(argv: list[str]) -> int:
    formats = None
    if argv and argv[0] == "--formats":
        if len(argv) < 2:
            print(json.dumps({"error": "--formats needs a value"}))
            return 2
        try:
            formats = zxingcpp.barcode_formats_from_str(argv[1])
        except Exception as exc:
            print(json.dumps({"error": f"unknown format {argv[1]!r}: {exc}"}))
            return 2
        argv = argv[2:]

    if not argv:
        print(json.dumps({"error": "no input files"}))
        return 2
    for path in argv:
        print(json.dumps(decode(path, formats)))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
