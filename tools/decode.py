#!/usr/bin/env python3
"""Decode barcode images and report what a real scanner sees.

The PHP test suite verifies its own encoders against its own tables, which
cannot catch a table that is wrong in the same direction as the test. This
script is the external opinion: it shells out to zxing-cpp, the reference
decoder, and reports the format and payload it recovers.

Usage:  decode.py [--formats NAME[,NAME...]] [--ean-add-on MODE] <image> [...]
Output: one JSON object per line, in argument order.

--formats narrows what the decoder is willing to see. It exists because some
symbologies are the same bars as another: a UPC-A symbol is an EAN-13 with a
leading zero, and with every format enabled zxing-cpp reports the EAN-13
reading. Asking for UPC-A is not making the test easier — the bars still have
to decode — it is asking the question the caller asked.

--ean-add-on says what to do with a two- or five-digit add-on printed beside
an EAN/UPC symbol: ignore it, read it if present, or refuse a symbol without
one. "require" is what verifies an add-on at all: zxing-cpp has no reader for
a lone add-on, so the only way to have a real scanner confirm those bars is to
put them next to a main symbol and insist both be read.
"""
import json
import sys

try:
    import zxingcpp
    from PIL import Image
except ImportError as exc:  # pragma: no cover - reported to the caller as data
    print(json.dumps({"error": f"decoder unavailable: {exc}"}))
    sys.exit(2)


ADD_ON_MODES = {
    "ignore": zxingcpp.EanAddOnSymbol.Ignore,
    "read": zxingcpp.EanAddOnSymbol.Read,
    "require": zxingcpp.EanAddOnSymbol.Require,
}


def decode(path: str, formats, add_on) -> dict:
    try:
        image = Image.open(path).convert("L")
    except Exception as exc:
        return {"file": path, "error": f"cannot read image: {exc}"}

    options = {}
    if formats is not None:
        options["formats"] = formats
    if add_on is not None:
        options["ean_add_on_symbol"] = add_on

    results = zxingcpp.read_barcodes(image, **options)
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
    add_on = None

    while argv and argv[0].startswith("--"):
        flag = argv[0]
        if len(argv) < 2:
            print(json.dumps({"error": f"{flag} needs a value"}))
            return 2
        value = argv[1]
        argv = argv[2:]

        if flag == "--formats":
            try:
                formats = zxingcpp.barcode_formats_from_str(value)
            except Exception as exc:
                print(json.dumps({"error": f"unknown format {value!r}: {exc}"}))
                return 2
        elif flag == "--ean-add-on":
            if value not in ADD_ON_MODES:
                print(json.dumps({
                    "error": f"unknown add-on mode {value!r}; "
                             f"expected one of {', '.join(ADD_ON_MODES)}"
                }))
                return 2
            add_on = ADD_ON_MODES[value]
        else:
            print(json.dumps({"error": f"unknown option {flag!r}"}))
            return 2

    if not argv:
        print(json.dumps({"error": "no input files"}))
        return 2
    for path in argv:
        print(json.dumps(decode(path, formats, add_on)))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
