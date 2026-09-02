#!/usr/bin/env python3
"""Decode barcode images and report what a real scanner sees.

The PHP test suite verifies its own encoders against its own tables, which
cannot catch a table that is wrong in the same direction as the test. This
script is the external opinion: it shells out to zxing-cpp, the reference
decoder, and reports the format and payload it recovers.

Usage:  decode.py <image> [<image> ...]
Output: one JSON object per line, in argument order.
"""
import json
import sys

try:
    import zxingcpp
    from PIL import Image
except ImportError as exc:  # pragma: no cover - reported to the caller as data
    print(json.dumps({"error": f"decoder unavailable: {exc}"}))
    sys.exit(2)


def decode(path: str) -> dict:
    try:
        image = Image.open(path).convert("L")
    except Exception as exc:
        return {"file": path, "error": f"cannot read image: {exc}"}

    results = zxingcpp.read_barcodes(image)
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
    if not argv:
        print(json.dumps({"error": "no input files"}))
        return 2
    for path in argv:
        print(json.dumps(decode(path)))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
