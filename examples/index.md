# Supported codes

Every symbology this library ships, each rendered by every renderer.
The list comes from the registry, so it is whatever this checkout
supports — regenerate it with `php examples/gallery.php`.

| Code | Name | Payload shown | Accepts |
| --- | --- | --- | --- |
| [QR Code](codes/qrcode.md) | `qrcode` | `https://scanmephp.dev` | any byte string, up to 2953 bytes at error correction level L |
| [Micro QR Code](codes/micro-qr.md) | `micro-qr` | `LOT4471` | up to 35 digits, 21 alphanumeric characters or 15 bytes, depending on the version and error correction level |
| [GS1 QR Code](codes/gs1-qr.md) | `gs1-qr` | `(01)09501101020917(10)LOT0001` | GS1 element strings, as (AI)data — e.g. (01)09501101020917(10)LOT0001 |
| [Code 128](codes/code128.md) | `code128` | `SCANME-2026` | printable ASCII (byte values 32 to 126) |
| [GS1-128](codes/gs1-128.md) | `gs1-128` | `(01)09501101020917(10)LOT0001` | GS1 element strings, as (AI)data — e.g. (01)09501101020917(10)LOT0001 |
| [Code 39](codes/code39.md) | `code39` | `PART-4471` | digits, A-Z, space and "-.$/+%" |
| [Code 39 Extended](codes/code39ext.md) | `code39ext` | `Part 4471/a` | any ASCII (byte values 0 to 127) |
| [Code 93](codes/code93.md) | `code93` | `Part 4471/a` | any ASCII (byte values 0 to 127) |
| [Codabar](codes/codabar.md) | `codabar` | `4917234` | digits and "-$:/.+", the delimiters being an option rather than data |
| [EAN-13](codes/ean13.md) | `ean13` | `5901234123457` | 12 digits, or 13 with a correct check digit |
| [EAN-8](codes/ean8.md) | `ean8` | `96385074` | 7 digits, or 8 with a correct check digit |
| [UPC-A](codes/upc-a.md) | `upc-a` | `036000291452` | 11 digits, or 12 with a correct check digit |
| [UPC-E](codes/upc-e.md) | `upc-e` | `04252614` | 7 or 8 UPC-E digits, or a UPC-A that compresses to one |
| [EAN-2](codes/ean2.md) | `ean2` | `52` | exactly 2 digits, no check digit |
| [EAN-5](codes/ean5.md) | `ean5` | `51299` | exactly 5 digits, no check digit |
| [ITF](codes/itf.md) | `itf` | `1234567890` | an even number of digits, or an odd number with the check digit option |
| [ITF-14](codes/itf14.md) | `itf14` | `1234567890123` | 13 digits, or 14 with a correct check digit |
| [Data Matrix](codes/data-matrix.md) | `data-matrix` | `ScanMePHP` | any byte string, up to 1556 bytes of ASCII or 778 digit pairs |
| [GS1 Data Matrix](codes/gs1-data-matrix.md) | `gs1-data-matrix` | `(01)09501101020917(10)LOT0001` | GS1 element strings, as (AI)data — e.g. (01)09501101020917(10)LOT0001 |
| [Aztec Code](codes/aztec.md) | `aztec` | `BOARDING-4471` | any byte string, up to roughly 3000 characters of text or 1900 bytes of binary |
| [PDF417](codes/pdf417.md) | `pdf417` | `SHIP TO: 123 Main St.` | any byte string, up to roughly 1850 characters of text or 1100 bytes of binary |
| [MaxiCode](codes/maxicode.md) | `maxicode` | `SHIP TO 123 MAIN ST` | any byte string, up to 93 codewords — about 93 characters of upper case text or 138 digits, and 84 codewords in the two structured modes |
| [GS1 DataBar Omnidirectional](codes/databar-omni.md) | `databar-omni` | `01234567890128` | 13 digits, or 14 with a correct check digit, optionally prefixed (01) |
| [GS1 DataBar Limited](codes/databar-limited.md) | `databar-limited` | `01234567890128` | 13 digits starting 0 or 1, or 14 with a correct check digit, optionally prefixed (01) |
| [GS1 DataBar Expanded](codes/databar-expanded.md) | `databar-expanded` | `(01)09501101020917(10)LOT0001` | GS1 element strings in parenthesised form, up to 22 symbol characters |
| [GS1 DataBar Expanded Stacked](codes/databar-expanded-stacked.md) | `databar-expanded-stacked` | `(01)09501101020917(10)LOT0001` | GS1 element strings in parenthesised form, up to 22 symbol characters |
| [RM4SCC](codes/rm4scc.md) | `rm4scc` | `LE28HS` | 1 to 50 digits and capital letters, typically a postcode and delivery point suffix |
| [KIX](codes/kix.md) | `kix` | `2500GG30250` | 1 to 18 digits and capital letters, typically a postcode, house number and additions |
| [Intelligent Mail](codes/intelligent-mail.md) | `intelligent-mail` | `01234567094987654321-01234` | 20 digits of tracking code, then 0, 5, 9 or 11 digits of routing code |
| [Australia Post](codes/australia-post.md) | `australia-post` | `96130590AB CD` | an 8-digit sorting code, optionally followed by 5, 8, 10 or 15 characters of customer information — 8 and 15 digits only |

A renderer that cannot draw a symbology faithfully says so on that
symbology's page rather than producing a symbol that does not scan.
