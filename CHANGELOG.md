# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The v2 rewrite: ScanMePHP becomes a barcode library rather than a QR code
generator. This is a full break with the 0.5.x API, with no compatibility shim
— see [UPGRADING.md](UPGRADING.md) for the call-site-by-call-site mapping.

A shim was considered and rejected. It would have to answer questions the old
API cannot ask — which symbology, which quiet zone, whether a renderer can
print the human-readable digits an EAN carries — and would answer them by
guessing. A guess that produces a scannable barcode for the wrong article
number is worse than a compile error.

### Added

- **Fourteen new symbologies** alongside QR: Code 128, Code 39, Code 39
  Extended, Code 93, Codabar, EAN-13, EAN-8, UPC-A, UPC-E, the EAN-2 and EAN-5
  add-ons, ITF, ITF-14, and Data Matrix (ECC200), each with its own generator,
  aliases and payload rules.
- **EAN-2 and EAN-5**, the add-on symbols printed beside a retail barcode: a
  periodical's issue number, a book's list price. They carry no check digit,
  so the digit count is exact — a third digit on an EAN-2 is a different
  add-on, not a checksum, and is refused rather than trimmed.
- **GS1-128**, as its own generator (`gs1-128`, aliases `gs1128`, `ean128`,
  `ean-128`, `ucc128`). Takes the parenthesised form GS1 prints under the bars
  — `(01)09501101020917(10)LOT0001` — and puts the FNC1 separators where the
  application identifier table says they go, plus the one after the start code
  that makes a reader announce `]C1`. The bars are Code 128 bars and the same
  encoder draws them; what makes it a different generator is that
  `canEncode()` has a different question to answer.
- **Aztec Code** (`aztec`, aliases `aztec-code`, `azteccode`): the matrix
  symbology with its finder in the middle and no quiet zone, which is why it is
  the usual choice on transport tickets and boarding passes. Roughly 3000
  characters of text or 1900 bytes of binary data.

  `AztecOptions` carries two things, and both are choices rather than
  implementation details. The **error correction percentage** is a floor, not a
  target: the symbol is sized to hold the data plus at least that much recovery
  data and whatever room is left over becomes recovery data too, so five
  characters come out of the smallest symbol with twelve of its seventeen
  codewords given to error correction and asking for 5% or for 40% produces the
  same symbol. The default is the 33% the encoders this library is checked
  against use; ISO/IEC 24778 recommends at least 23%. The **symbol size** can
  be pinned, and it is a size rather than a layer count on purpose — four
  layers is a compact 27-module symbol *and* a full 31-module one, whereas the
  thirty-six sizes do not collide.

  Binary payloads work directly: the 142 bytes with no place in Aztec's five
  character modes go through a binary shift, and where to open one is searched
  for rather than guessed. A single lower-case letter inside a word of capitals
  is cheapest as a one-byte binary shift, eighteen bits against the nineteen a
  latch and the route back would cost.

  Not implemented: FLG(n), the Punct code that carries an ECI or an FNC1.
  Nothing asks for either yet — a GS1 Aztec would — and an encoder that emitted
  one by accident would be worse than one that cannot. Like Data Matrix, Aztec
  is pure PHP only; native acceleration stays QR-only by design.
- **PDF417** (`pdf417`, alias `pdf-417`): a stack of independently readable
  linear rows, which is what driving licences, boarding passes and shipping
  labels are printed with — a scanner recovers the data from a few sweeps
  across the symbol rather than needing it square in frame. Roughly 1850
  characters of text or 1100 bytes of binary data.

  `Pdf417Options` carries four things, three of which are preferences rather
  than facts about the data. The **shape** — the column count, and a floor
  under the row count — is a request, because any grid with enough cells holds
  the codewords and the spare cells become pad codewords; the default is six
  columns, since every row also spends the width of four data columns on its
  start pattern, two row indicators and stop pattern, so a one-column symbol
  gives four fifths of its width to structure. The **error correction level**
  is a real level, 0 to 8, each doubling the last one's check codewords from
  two to 512, and the default is what ISO/IEC 15438 recommends for the amount
  of data. The **row height** is presentation: PDF417 rows carry nothing
  vertically, so three modules is convention rather than meaning.

  That last one made PDF417 the first matrix symbology here whose rows are not
  one module tall, and it needed no new mechanism — a symbol states its own row
  heights and the renderers already honoured them, which is the same path a
  linear symbology's bar height takes and the one the four-state postal codes
  will take for their ratios.

  Three compaction modes — text with four submodes, numeric in base 900, byte —
  and which to use is searched for exactly rather than guessed at. The search
  is linear in the payload despite covering every segmentation, because both
  group structures are finite: what one more character costs depends only on
  how far into its group it falls, so fifty-eight states per position cover
  everything. Across 148 payloads swept against zxing-cpp it was never longer
  and never shorter — half identical, half the same length by another route.

  Not implemented: the ECI header and the macro block. No ECI means bytes pass
  through as they are and the reader's charset assumption applies, which is
  worth knowing because zxing declares binary input with one and so produces a
  different symbol for the same bytes. No macro block means a payload too large
  for one symbol is refused rather than split across several. Like Aztec,
  PDF417 is pure PHP only; native acceleration stays QR-only by design.
- **MaxiCode** (`maxicode`, aliases `maxi-code`, `ups-code`): hexagons around a
  bullseye, one fixed size, made for parcels. It is the odd one out of
  everything here — the modules are hexagons on interlocking rows rather than
  squares on a grid, the finder is three concentric rings in the middle rather
  than patterns in the corners, and there is no version, no layer count and no
  error correction level to choose. 93 codewords: about 93 characters of upper
  case text, or 138 digits, since nine digits compact into six codewords.

  `MaxiCodeOptions` carries the **mode**, and unlike every other option in this
  library it changes what the symbol *means* rather than how it is drawn. Modes
  2 and 3 spend the nine codewords nearest the bullseye on a structured carrier
  message — a postcode, an ISO country code and a carrier's service class —
  which a reader reports as three fields in front of the payload rather than as
  a prefix glued onto it. That is what the symbology is for, and it is why those
  two modes hold 84 codewords of payload instead of 93. Mode 2's postcode is up
  to nine digits and mode 3's is six positions rather than a string of six, so a
  shorter one comes back space-padded.

  Binary payloads work directly, and MaxiCode is the only symbology here that
  reaches every byte with **no binary mode at all**: its five code sets between
  them carry all 256 values. Which set to be in is a search rather than a rule,
  because they overlap — a space is in all five, a comma in three — and it is
  exact, with five states, one pass and no lookahead limit. Ties are the normal
  case and two cost-neutral rules break them: a latch waits until a character
  the open set cannot write, and a single shift beats an equal-cost latch while
  a two- or three-character shift loses to one.

  Not implemented: **mode 5**, the enhanced error correction variant. Its
  secondary message splits 68 data codewords against 56 check ones rather than
  84 against 40, the interleaving is not the plain mode's, and nothing available
  to check against writes one — a sweep of thirty candidate splits produced no
  symbol a reader would accept, so shipping a guess would mean shipping a mode
  that cannot be verified. Like Aztec and PDF417, MaxiCode is pure PHP only.
- **Hexagonal modules in the SVG and PNG renderers**, and the first time the
  shape negotiation that has been in `RendererCapabilities` all along actually
  declines something. MaxiCode's rows interlock — a row sits 0.866 of a module
  below the one above and every odd row is offset half a module — so a renderer
  cannot substitute a hexagon for a square and be done: the canvas is shorter
  than the row count suggests, and the PNG writer's "this scanline is the same
  as the one above" trick, which carries a whole module row for the cost of one,
  does not apply at all. `Renderer\HexagonLattice` holds the geometry both
  renderers share.

  The bullseye is the other half of it. Three concentric rings are not modules
  and have no representation in a grid of light and dark cells, so the symbol
  reports it as a finder region and the renderer draws it from the radii — a
  real division of labour rather than a hint, since a renderer that ignores the
  region emits a symbol with a hole where its finder should be. The ASCII and
  HTML renderers refuse a hexagonal symbol by name, which is better than
  approximating it into something that looks like a barcode and does not scan.

  The two refusals are not the same kind of thing, and the docblocks now say so.
  A terminal genuinely cannot draw this: its cells are a fixed raster with no
  way to offset a row by half a cell. HTML could — `clip-path` draws a hexagon
  and `border-radius` a ring — and refuses because `HtmlRenderer` is built
  around one element per module in a grid, so a lattice is a second rendering
  path rather than a variation. That is scope, it is recorded as Tier 6 in
  ROADMAP.md, and claiming otherwise in `ModuleShape`'s docblock was overstating
  the case.
- **`RegionRole`**, which makes the difference between a finder a renderer may
  style and a finder a renderer must draw something the type system can see.
  Finder regions started as a hint — QR reports its three corner patterns so the
  SVG renderer can round them, and a renderer that ignores them draws the same
  scannable symbol. MaxiCode's bullseye is not a hint: three concentric rings
  are not modules, the grid is blank where the finder goes, and ignoring the
  region produces a symbol with a hole in the middle.

  So the same field was carrying two contracts and the difference was invisible
  — both were a rectangle of modules. `RendererCapabilities::$drawnRegions` now
  declares which renderers can supply one, `Compatibility` refuses the pair by
  name, and the refusal is independent of module shape: a square-moduled symbol
  with a renderer-drawn finder is refused for that reason alone. Every existing
  region defaults to `RegionRole::InGrid`, so QR, Aztec and the rest are
  unchanged.
- **`Encoding\MaxiCode\Placement`**, the second measured table here, and the
  measurement is arranged so that trusting the oracle is not what makes it
  right. `tools/maxicode_placement.py` computes all 144 codewords itself — code
  sets, then Reed–Solomon in three blocks — and matches each of the 974 lattice
  positions to the (codeword, bit) whose value it tracks across a hundred
  payloads. A position and a bit that agree on a hundred independent symbols are
  the same thing, and the match confirms the arithmetic and the placement
  together: three of the four candidate interleavings of the secondary message
  line up under 600 of 813 varying positions, and the right one lines up with
  every single one.

  Two things that cannot reach: the mode codeword is constant across those
  payloads, so its six cells were found by exhausting the primary message's
  error correction instead — with five of RS(20,10)'s five correctable errors
  already spent, one more flipped module breaks decoding if and only if it
  belongs to a codeword, and exactly six cells do. And bits 3 to 5 of that
  codeword are zero in every mode the standard defines, so no symbol can
  distinguish them; the order used is the one the regular blocks use and it is
  recorded as undetermined rather than passed off as measured.

  The raster is useless for any of this, which is worth stating because every
  other fixture here samples one: MaxiCode's modules are hexagons, so one pixel
  is not one module, and three attempts at fitting the lattice to a raster
  disagreed with each other across scales before the SVG turned out to state it
  exactly.
- **`Encoding\Pdf417\ReedSolomonGf929`**, Reed–Solomon over a prime field.
  Neither existing implementation generalises to it, and not for want of
  trying: over GF(2^m) addition *is* exclusive-or, which is why both of the
  others add with `^`. 929 is prime, so every `^` would have to become an
  addition modulo 929 — a different arithmetic, not a wider one. Anchored
  against zxing-cpp's own check codewords for a symbol it produced, and the
  sign convention is the part that matters: PDF417's check codewords are the
  negated remainder, and getting that wrong yields plausible codewords no
  reader accepts.
- **`Encoding\Pdf417\CodewordPatterns`**, the only table in this library that
  is measured rather than derived — and the measurement is arranged so that
  trusting the oracle is not what makes it right. Which cluster a pattern
  belongs to *is* derivable, from the alternating sum of its four bar widths
  modulo nine, and that holds for all 2787 entries. The assignment of values to
  patterns within a cluster is not: sorting each cluster by the pattern as an
  integer or by its width tuple, either direction, places at most two of several
  hundred known values correctly. So `tools/pdf417_codeword_table.py` seeds the
  table from row indicator values this library states itself from the geometry,
  then grows it by predicting whole symbols and learning only from those where
  every already-known cell agrees — a symbol that disagrees anywhere is
  discarded whole. Roughly half are refused. `Pdf417CodewordPatternsTest` then
  guards the result by re-deriving every cluster and checking each is a
  bijection over patterns that are seventeen modules of eight elements one to
  six wide starting with a bar.
- **`Encoding\ReedSolomonGf2m`**, Reed–Solomon over GF(2^m) for any m.
  `ReedSolomon256` could not be reused: it is deliberately hardcoded to GF(256)
  — a 256-row factor table and no log lookups in the inner loop — because that
  is what makes it fast enough for QR's hot path, and Aztec needs five fields in
  one symbol family, GF(16) for the mode message and GF(64) through GF(4096) by
  layer count. Widening the tuned class would have cost QR for no gain here.
  The new class is anchored two ways: against the published ISO/IEC 16022
  Annex R vector through the field it shares with Data Matrix, and end to end
  through the Aztec fixture for the other four. It sits in `Encoding` rather
  than `Encoding\Aztec` because MaxiCode turned out to need GF(64) too — the
  same field, the same primitive polynomial, and no change to the class.
- **A real reference fixture for QR** (`tests/fixtures/qr_reference.csv`,
  regenerated by `composer reference:qr` from Nayuki's qrcodegen). QR is the
  oldest code here and its fixture predated the rule that every symbology is
  compared against an encoder we did not write: what the file held was our own
  output, frozen. That catches a change but not a mistake present from the
  start — and QR was the one symbology with four backends and a C++ core behind
  it. The same 443 URLs at all four levels now come from an outsider, with the
  mask held fixed for the reason above, and all 1772 match module for module.
  The backends that cannot be told a mask are covered by the new
  `QrBackendAgreementTest`, which requires the bitset fast path, the FFI bridge
  and the extension to produce byte-identical modules to the verified encoder —
  so the oracle reaches the C++ core rather than stopping at pure PHP. The C++
  suite is tested from C++ and cannot call that encoder, so it compares against
  `tests/fixtures/qr_agreement.csv`, which is the encoder's own output frozen
  and is deliberately not a reference fixture; `QrAgreementFixtureTest` asserts
  it still is that output, so it cannot rot into last month's symbols and go on
  reporting a pass.
- **The QR mask pattern as an option** — `QrOptions(mask: 0..7)`, null and
  automatic by default, honoured by `qrcode` and `gs1-qr` alike. Which of the
  eight to use is a preference rather than a requirement: all eight carry
  identical data, all of them scan, and conforming encoders disagree about it
  routinely. A caller reproducing another system's symbols byte for byte, or
  pinning output for a golden-file test, was previously unable to say which
  one. Pinning narrows backend selection exactly as pinning a version already
  did, via the new `QrBackendInterface::supportsForcedMask()`; only the
  portable encoder can honour it, and a registry without one reports the pin by
  name instead of quietly ignoring it. Every mask is round-tripped through the
  decoder for both symbologies, since an option that can produce an unreadable
  symbol is a way to fail at the till.
- **GS1 QR** (`gs1-qr`, aliases `gs1-qrcode`, `gs1qr`): the third and last
  spelling of FNC1, and the odd one out — a *mode indicator*, four bits ahead
  of the first segment, rather than a value in the same alphabet as the data.
  The payload is unchanged from the other two, separators included. Pure PHP
  only: the C++ core exposes `encode(data, len, ecl)` and has nowhere to put
  the indicator, and native acceleration stays QR-only by design.
  Verified against Nayuki's qrcodegen module for module
  (`tests/fixtures/gs1_qr_reference.csv`, 44 symbols) with the mask held fixed,
  which is a boundary worth stating: masking is the one step where conforming
  QR encoders legitimately disagree. ISO/IEC 18004 clause 7.8.3 says to score
  all eight and take the lowest, but the rules — chiefly rule 3, the 1:1:3:1:1
  pattern — are read differently in practice and ties are ordinary. Over sixty
  random byte payloads, zxing-cpp and qrcodegen produced the same modules eight
  times. All eight maskings carry identical data and all of them scan, so the
  fixture pins the mask rather than asserting it, and the comparison still
  covers the version, the FNC1 indicator, the codewords, the error correction,
  the interleaving and the placement. `tests/Gs1QrTest.php` declares it.
- **GS1 Data Matrix** (`gs1-data-matrix`, aliases `gs1-datamatrix`, `gs1dm`):
  the same element strings in an ECC200 symbol. FNC1 is codeword 232 here
  rather than a symbol character — one in front, one per separator — and
  everything downstream of encodation is shared with plain Data Matrix through
  the new `DataMatrix\SymbolBuilder`, so size selection, block interleaving
  and the finder frame cannot drift between them.
- **The GS1 application identifier table**
  (`Generator\Gs1\ApplicationIdentifier`, 541 identifiers). Derived, not
  transcribed: `tools/gs1_reference.py` sweeps every two-, three- and
  four-digit string past zxing-cpp and keeps what it accepts, then probes each
  one's legal data lengths and separator rule. Three identifiers turn out to
  accept a *set* of lengths rather than a range, and predefined length turns
  out not to mean fixed length — `(402)` is seventeen digits and still needs a
  separator. Frozen in `tests/fixtures/gs1_ai_reference.csv`; the table is
  compared against it row for row.
- **A reference fixture for Code 128** (`tests/fixtures/code128_reference.csv`,
  141 symbols from zxing-cpp). Code 128 shipped before the rule that every
  symbology gets one, so it was the last linear code verified only against its
  own table. Comparing module for module against an independent encoder also
  pins the character-set switching, which a round trip cannot see.
- **Add-on placement**, via `Ean\Composite::of()`: an EAN-13, UPC-A or UPC-E
  with its add-on beside it, as one `Symbol` every renderer draws. Not a
  concatenation — there is a seven-module gap, the add-on's bars are drawn
  shorter than the main symbol's, and its digits go *above* its bars, since the
  line below already carries the main symbol's own. An EAN-8 is refused: GS1
  defines no add-on for it, and the pair would scan while being a label a
  retail system may reject.
- **Positioned human-readable text.** `Symbol` accepts `TextRegion`s — text
  with a placement and the module columns it belongs over — which is what makes
  the above drawable. A plain `text` is still shorthand for one line centred
  underneath, so nothing else changed. Renderers declare `positionedText` in
  their capabilities; it defaults to **false**, so a renderer written before
  this existed is reported as unable rather than assumed able, and a composite
  handed to one is refused by name instead of printing the price under the
  middle of the label.
- **Code 39 and Code 39 Extended**, registered as two symbologies rather than
  one with a flag. They are the same bars: extended mode reaches the whole of
  ASCII by spending two characters on each byte outside the standard 43, and
  nothing in the printed pattern says which reading is meant — a scanner
  configured for one reads `A$B` as three characters and a scanner configured
  for the other reads it as two. Making the mode a symbology is what lets
  `canEncode()` answer at all: `'hello'` is encodable as one and not the other,
  and the same five bytes are ten characters wide in one and impossible in the
  other. `Code39Options` carries what is genuinely optional — the modulo-43
  check character (off by default: most readers do not verify it and report it
  as trailing data) and the wide-to-narrow ratio.
- **Code 93**, one registry entry where Code 39 is two, and no options at
  all. Both things a caller might expect to choose are the two Code 39 makes
  optional: the check characters are mandatory here — a weighted pair, so
  unlike Code 39's unweighted sum they see a transposition — and full ASCII is
  part of the symbology rather than a second reading of it, because the shift
  characters have bars of their own instead of borrowing a data character's.
  So `A$B` has one reading here and two in Code 39. Nine modules a character
  against thirteen makes it the denser of the pair: 81% of the Code 39 width at
  eleven characters, 72% at fifty-nine.
- **ITF and ITF-14.** ITF interleaves digits in pairs, so the digit count must
  be even — and an odd one is **refused rather than padded with a leading
  zero**, which is what most encoders do and is a change to the caller's data.
  The optional GS1 check digit flips which parity encodes, so this is one of
  the few places where `canEncode()` has to read the options to answer.
  ITF-14 is registered separately rather than being a fourteen-digit ITF,
  because three things a caller must not have to remember are fixed there: the
  digit count, the mandatory check digit, and the bearer bar. The bearer bar is
  drawn as modules — ITF is not self-checking, and the frame is what stops a
  beam that clips a guard from reading a valid shorter number — with the 10X
  quiet zone *inside* it, which is where GS1 puts it and where a frame drawn
  flush against the bars would leave none at all.
- **Codabar**, with the delimiters as options rather than payload. Most
  implementations make the caller write them in — `'A4917234A'` rather than
  `'4917234'` — which puts a detail of the symbology into the caller's data and
  makes `canEncode()` refuse every number they hold. A scanner reports them
  regardless, so `getText()` is what belongs under the bars and the
  `characters` metadata is what a scan reads back. The four delimiters are also
  spelled T, N, * and E, and `Delimiter::fromName()` accepts both spellings
  because they are the same bars. **No check character**: the variants in
  circulation disagree and nothing available here writes or validates any of
  them, so shipping one would mean a barcode table with no independent check —
  the one thing this library does not do. Compute the variant your system needs
  and append it to the payload.
- **`Scanme`**, the one entry point: `render()`, `generate()`, `renderSymbol()`,
  `dataUri()`, `toFile()`, `getContentType()`, `supports()`.
- **`Registry` and `Defaults`** — an open registry of generators and renderers.
  Nothing in it is privileged; a registration under an existing name replaces
  it, so swapping the SVG renderer for your own needs no fork.
- **Capability negotiation.** Generators publish `GeneratorCapabilities`,
  renderers publish `RendererCapabilities`, and `Compatibility::check()`
  matches them. An impossible pair throws `IncompatibleRendererException`
  naming every reason, rather than emitting something that looks like a barcode
  and does not scan.
- **`Symbology` and `Format` enums**, accepted anywhere a name is. Every API
  still takes `string|Enum`: a closed enum would make a generator registered
  from outside this package a second-class citizen.
- **Introspection** — `describeGenerators()` publishes what is installed and
  what each entry accepts; `generatorsFor($data)` answers which symbologies can
  take a payload, so a caller holding a number need not guess.
- **Backends per generator.** `BackendSelector` picks the fastest available
  encoder at runtime; QR ships four (native, ffi, bitset, portable) producing
  identical modules. `force()` pins one for a benchmark or a test.
- **Independent decoder verification.** Every symbology is rendered to a real
  PNG and read back by zxing-cpp on every CI build (`composer test:roundtrip`,
  `SCANME_REQUIRE_DECODER=1`). Reference fixtures are generated by encoders we
  did not write and compared module for module. zxing-cpp has no reader for a
  lone EAN-2 or EAN-5, so those two are gated by decoding a composite — our
  add-on bars printed beside our EAN-13, with the decoder told to refuse a
  symbol that has no add-on — and a second test fails the day zxing-cpp learns
  to read them standalone, so the substitution cannot outlive its reason. The
  Code 39 fixture is exhaustive where exhaustion is cheap: all 43 characters and
  all 128 ASCII bytes, each as its own symbol, because a swapped row in either
  table gives a symbol that still scans as something else.
- **`UPGRADING.md`** and seven runnable examples covering the API end to end.
- **`tests/ExamplesTest.php`**, which runs every example on every build. The
  0.5.x examples had been dead for some time and nothing noticed, because
  nothing executed them.

### Changed

- **`Matrix` → `Symbol`.** Symbols are rectangular, carry a quiet zone, may
  have rows of differing heights (EAN guard bars descend below the others),
  and carry the human-readable text a linear symbology requires be printed.
- **Renderers are addressed by format name**, not constructed and injected as
  an `engine`. Their constructor arguments moved into per-renderer option bags.
- **Options are split by what they affect.** Generator options change the
  modules (`QrOptions`, `DataMatrixOptions`); render options change the picture
  (`SvgOptions`, `PngOptions`, `HtmlOptions`, `AsciiOptions`). Bags are routed
  by the interface they implement, so order does not matter and either may be
  omitted; a bag nobody claims is an error rather than a silent no-op.
- **`margin` → `quietZone`, and it now defaults per symbology** — 4 modules for
  QR, 11 left and 7 right for EAN-13, 9 and 7 for UPC-E. Those widths are part
  of being scannable. An explicit value still wins, including a smaller one.
- **`size` → `version`**, and a number is a floor rather than an exact size:
  data that does not fit still grows the symbol. `null` means auto.
- `bench/benchmark_render.php` and `bench/benchmark_e2e.php` rewritten on the
  new API; the render benchmark now walks the registry rather than a
  hand-maintained class list, and takes a symbology argument.
- **Code 128 now encodes optimally.** The switch between character sets B and C
  was a threshold heuristic — six digits, or four ending the payload — which is
  the kind of rule that is wrong quietly: a symbol encoded a character wider
  than it needs to be still scans as the right data. It is now a linear
  dynamic program over the two sets, so the encoding is the shortest one that
  exists, and odd-length digit runs in particular are narrower than before.
  Ties go to set C, which is what the independent encoder chooses.
- README, BENCHMARK.md and AGENTS.md rewritten for the current API.

### Removed

- `QRCode`, `QRCodeConfig`, `RenderOptions`, `RendererInterface` in the root
  namespace, and the seven per-renderer classes (`SvgRenderer`,
  `PngRenderer`, `HtmlDivRenderer`, `HtmlTableRenderer`, `FullBlocksRenderer`,
  `HalfBlocksRenderer`, `SimpleRenderer`).
- `QRCode::toHttpResponse()`. It sent headers and called `exit`, which no
  framework wants and no test can call twice. `getContentType()` still gives
  you the one thing the library actually knows.
- The committed `examples/generated-assets/`. A checked-in artefact nobody
  regenerates goes stale exactly the way the examples did; the directory is now
  rebuilt by running an example.

### Unchanged

- `Encoder`, `FastEncoder`, `FfiEncoder` and `NativeEncoder` keep their
  signatures and still return a `Matrix`. Code driving them directly — a
  benchmark, a custom pipeline — is unaffected.
- The C++ core, the PHP extension, the FFI binaries and their install paths.

## [0.5.2] - 2026-08-26

v0.5.1 has no binaries behind it: every extension build failed, so `Create
Release` — which needs them all — was skipped. PHP 8.1 was the cause, and since
Packagist had already published v0.5.1 the tag could not be moved. Installing
0.5.1 from Composer is fine and gets the pure-PHP encoder; 0.5.2 is the version
with binaries.

### Removed

- Prebuilt extension binaries for PHP 8.1. `composer.json` has required `^8.2`
  since 0.5.0 and CI only ever tested 8.2–8.4, so those four binaries were built
  for a PHP the library refuses to install on. They also stopped building: 8.1's
  `PHP_CXX_COMPILE_STDCXX` does not accept `20`, and the C++ core needs C++20.
  Nothing in CI covered that, because the release matrix built a PHP version CI
  did not test.

### Fixed

- `config.m4` tests for `-std=c++20` directly instead of going through
  `PHP_CXX_COMPILE_STDCXX`, whose accepted arguments vary with the PHP being
  built against.

## [0.5.1] - 2026-08-26

Packagist serves v0.5.0 from `360ee07`, one commit before the tag: v0.5.0 was
re-tagged after its first release build failed on Windows, and Packagist holds
published versions immutable. Nothing a caller executes differs between the two
— the commit in between changed only comments, workflows, the README and the
CMake flags — but the way to bring the two back in line is a new tag, which is
this one.

### Added

- The extension is published as a PIE package,
  [crazy-goat/qrcode-ext](https://github.com/crazy-goat/qrcode-ext), so it can be
  built from source on platforms no prebuilt binary covers:
  `pie install crazy-goat/qrcode-ext`. The repository is generated from `php-ext/`
  and `clib/` by `bin/build-ext-mirror.sh`; it exists separately only because PIE
  requires the package name to differ from the library's and Packagist reads
  `composer.json` at a repository root.
- `php-ext/tests/*.phpt`, which exercise the extension without the Composer
  package installed — the mirror ships them and `make test` runs them.

### Changed

- The extension compiles the C++ core into itself instead of linking a prebuilt
  `libscanme_qr`, so `phpize && ./configure && make` is now the whole build and
  CMake is needed only for the FFI library and the C++ tests. On x86-64 the two
  SIMD kernels are still the only files compiled with `-mavx2` / `-mavx512f`;
  applying those flags to the whole extension would let the compiler emit
  instructions the runtime dispatcher never checked for.
- `./configure --with-scanmeqr=DIR` is now `--with-scanmeqr-clib=DIR`, and it
  defaults to `../clib` — the old name is taken by `--enable-scanmeqr`, which PIE
  needs to default to on.
- The extension reports its own version as the library's (`0.5.1`) instead of a
  frozen `1.0.0`; `bin/build-ext-mirror.sh` refuses to publish a tag that does not
  match it.
- CI builds the extension and assembles the PIE package on every run. Both were
  previously built only by `release-build.yml`, which fires on a tag — so a break
  in either was discovered with the release already tagged.

### Fixed

- `encodeRaw()` no longer emits an "Undefined property" warning before throwing
  when handed an object that is not an int-backed enum.

## [0.5.0] - 2026-08-26

Performance release (see `OPTIMIZATION_RESULTS_2026-08.md` for before/after
numbers). Minor bump rather than a patch because three things change what
callers observe:

- PHP 8.1 is no longer supported (`composer.json` requires `^8.2`)
- Windows no longer gets prebuilt binaries — the pure-PHP encoder still works
  there, but the FFI/extension fast paths need a local build
- `SvgRenderer` in the default Square style emits one `<path>` of merged
  horizontal runs instead of one `<rect>` per module — same rendered pixels,
  ~4.5× smaller files, but different markup for anyone parsing or diffing it
- `PngRenderer` defaults to zlib level 1, so files are ~1 KB larger at v10;
  pixels are unchanged and `new PngRenderer(compressionLevel: 6)` restores the
  previous size

### Added

- `clib/bench/scanme_bench` (CMake option `BUILD_BENCH`): C++-only benchmark
  with per-version latency and a per-stage breakdown (codewords, RS,
  placement, mask selection, apply); `csv` mode for scripting
- `clib/tests/test_penalty_equivalence`: checks the lane-parallel mask
  selection against the scalar nayuki-style reference for v1–v40, all ECLs,
  all 8 penalties; CI now runs the C++ tests once per SIMD kernel
- `SCANME_MASK_KERNEL=generic|avx2|avx512` environment override to force a
  mask-penalty kernel (tests/benchmarks)
- `Matrix::__construct(int $version, ?array $data = null, bool $normalized = true)`
  accepts prefilled module data so native encoders skip the `array_fill()`
  and a second per-module pass; the public raw getters still return `bool[]`
- `Matrix::fromModuleString(int $version, string $modules)`: builds a matrix
  from one `'0'`/`'1'` byte per module and stores the string as-is (reads go
  through the same `(bool) $data[$i]` path; the first write or raw-array
  getter normalises it to `bool[]`). Used by `FfiEncoder` and the `scanmeqr`
  extension; `tests/MatrixTest.php` covers the bool[] / int[] / string
  representations
- `Matrix::toModuleString()`: the symbol as one `'0'`/`'1'` byte per module,
  cached for array-backed matrices until the next write — the input all
  renderers now work on
- `PngRenderer(compressionLevel: int = 1)`: zlib level for the IDAT stream
- `PngEncoder::encodeScanlines()`: encode pre-filtered scanline bytes
- `tests/RendererTest.php`: pins every renderer to a naive per-module
  reference and to identical output for bool[] / int[] / string matrices
- `bench/benchmark_e2e.php`: component + end-to-end benchmark that can run
  against another checkout; `OPTIMIZATION_RESULTS_2026-08.md` holds the
  before/after report of the 2026-08 pass

- Agent workflow (`.workflow/workflow.md`) adapted from the workerman-bundle
  workflow: issue → feature branch → subagent implementation → review rounds
  → PR → CI → merge, with proof-of-work files under `.workflow/proof_of_work/`
  and a knowledge base under `.workflow/helpers/` (`faq.md`, `decisions.md`)
- `CONTRIBUTING.md` with contribution guidelines, linked from the README (#196)
- Workflow helper scripts in `bin/`: `gh-branch` (derive a feature branch
  from an issue), `pick-issue.php` (rank open issues by labels/age/comments),
  `kb-lint.php` (validate the knowledge base and regenerate its tag index)
- Dev tooling via `composer lint` / `composer lint-fix`: PHPStan (level 4
  with a baseline), php-cs-fixer (PSR-12), Rector (PHP 8.2+ modernizing rules)
- `composer/composer` as a dev dependency so PHPStan can resolve the
  Composer plugin interfaces in `src/Composer/`
- Docker test image (`docker/Dockerfile`) and wrapper script (`docker/test.sh`) to run the test suite on a supported PHP version (8.4 by default) without changing the system PHP; mirrors the CI environment (ffi + gd extensions, composer, C++ build tools for `clib/`)

### Changed

- Renderers rewritten around `Matrix::toModuleString()` and whole-matrix
  string operations (`substr`/`strtr`/`str_replace`/`preg_match_all`) instead
  of one `Matrix::get()` call per module. v10 / v27 render time (µs):
  FullBlocks 47→13 / 232→74, HalfBlocks 85→11 / 403→56, Simple 47→13 /
  235→72, HtmlDiv 332→45 / 1363→199, HtmlTable 338→43 / 1409→314,
  Svg 251→80 / 1166→376, Png 3161→123 / 14181→633. ASCII and HTML output is
  byte-identical. Full before/after report incl. end-to-end numbers in
  `OPTIMIZATION_RESULTS_2026-08.md`
- `SvgRenderer` (Square style) merges horizontal runs of dark modules into a
  single `<path>` instead of one `<rect>` per module — same pixels when
  rasterised, ~4.5× smaller files (v10: 103 → 22 KB). Finder patterns keep
  their per-module rounded `<rect>`s; Rounded/Dot styles emit the same
  elements as before (finder rects now come first)
- `PngRenderer` stores the `moduleSize − 1` repeated scanlines of each module
  row with the PNG *Up* filter (all zeros) and defaults to zlib level 1:
  7× faster compression for a ~1 KB larger file (v10: 1.4 → 2.4 KB); pass
  `compressionLevel: 6` for the previous size. Pixels are unchanged
- The `scanmeqr` extension returns a string-backed `Matrix`
  (`Matrix::fromModuleString`) like `FfiEncoder`: encode v10 9 → 7 µs, and
  renderers skip the `bool[]` → string conversion

- Pure-PHP `FastEncoder` is 20–50× faster (PHP 8.5 + JIT, Apple M-series:
  v1 233 → 15 µs, v10 1465 → 60 µs, v20 ~5 ms → 200 µs; ~4× slower without
  the JIT). Mask selection evaluates penalty rules bitwise on whole rows and
  columns (the same formulation as the C++ kernel) instead of visiting every
  module of every mask; Reed–Solomon keeps each block's remainder in four
  packed 64-bit words; placement walks the stream per byte; the matrix is
  expanded through a string LUT + `unpack()`. Output is byte-for-byte
  unchanged (cross-checked against the C++ library for v1–v27)
- `Encoder` delegates Byte-mode symbols up to v27 to the `FastEncoder` bitset
  path (`FastEncoder::encodeVersion()`, honours a requested version) and only
  runs its scalar pipeline for v28–v40, so the portable encoder is as fast as
  `FastEncoder` for every size it covers
- `bench/benchmark_*.php`: the case labelled "v10 (57x57) M" was a v12 symbol
  (260 bytes exceed v10-M capacity); relabelled
- C++ encoder (`clib/`) is 12–16× faster: v1 21 → 1.5 µs, v10 68 → 6 µs,
  v40 1276 → 80 µs (Apple M-series). Mask selection now evaluates all 8 masks
  lane-parallel with bitwise penalty rules (templated on row width), the
  kernel is compiled for SSE2 / AVX2 / AVX-512 on x86-64 with runtime
  dispatch (NEON on arm64), Reed–Solomon runs on 4×`uint64` with per-ec_count
  cached tables, data placement is branch-free and the byte expansion is
  table-driven. Output is byte-for-byte unchanged
- PHP boundary of the native encoders: the extension fills the `Matrix` array
  with `ZEND_HASH_FILL_PACKED` and calls the two-argument constructor;
  `FfiEncoder` uses `unpack('C*')` instead of `array_chunk` + nested
  `array_map`. End to end (PHP 8.5, arm64): ext v10 33 → 16 µs, FFI v10
  167 → 35 µs
- `FfiEncoder` no longer builds a size² PHP array at all: the C library's
  0/1 bytes go through one `strtr()` into `Matrix::fromModuleString()`. FFI
  encode time drops 3× (v1 5 → 3 µs, v10 24 → 8 µs, v27 113 → 39 µs), on par
  with the extension. Rendering a string-backed matrix is ~5–15 % slower than
  a `bool[]` one, so the extension deliberately keeps filling `bool[]` in C
  (encode + render is cheaper that way; see BENCHMARK.md)
- `QRMatrix` no longer maintains a column-major copy; `clib/tests/CMakeLists.txt`
  only lists the tests that exist (the `BUILD_TESTS=ON` build was broken)
- `bench/benchmark_*.php` resolve the FFI library via
  `FfiEncoder::localBuildPath()` (`.dylib` on macOS) instead of a hardcoded `.so`
- Minimum PHP raised from 8.1 to 8.2 (`composer.json`, CI matrix). The
  precompiled extension binaries are still built for 8.1 in `release-build.yml`
- CI test matrix is now PHP 8.2, 8.3, 8.4 (8.1 dropped)

### Fixed

- `bin/gh-branch` is executable directly again — it was missing its PHP
  shebang, so running it as documented failed (#190, #191)
- `FastEncoder` produced a sub-optimal (still valid, but different from the
  reference) mask for v20–v27: penalty rules 2 and 4 only popcounted the low
  32 bits of the `hi` word, so modules in the first columns were not counted
  once the symbol exceeded 96 modules. The reference fixtures only cover
  v2–v11, which is why it went unnoticed; the new bitwise implementation
  matches `Encoder` and the C++ library for all of v1–v27
- `Builder::build()` now runs each build command (cmake, make) exactly once
  instead of twice (it previously ran `shell_exec()` for output and `exec()`
  again for the exit code). Stderr is no longer merged into captured output
  (`2>&1` removed), so local paths and environment details are not leaked via
  exception messages; build failures now throw a sanitised `BuildException`
  with the exit code only (#57)
- `QRCode::createDefaultEncoder()` now checks `extension_loaded('scanmeqr')`
  (the correct module name per `php-ext/scanme_qr.c`) instead of the misspelled
  `scanme_qr`, so the native C extension is actually selected when loaded (#39)
- `NativeEncoder` no-extension fallback no longer throws `ArgumentCountError`
  (`new FfiEncoder()` required a library path); it now resolves the FFI library
  via the shared `FfiEncoder::resolveLibraryPath()` and throws a clear
  `RuntimeException` when no library is available (#39)
- Centralized FFI library path resolution into `FfiEncoder::resolveLibraryPath()`
  as the single source of truth used by both `QRCode` and `NativeEncoder`, with
  a consistency test pinning all `extension_loaded('scanmeqr')` call sites (#39)
- `FfiEncoder::resolveLibraryPath()` and the FFI test entry points no longer
  hardcode the Linux `.so` suffix for the local CMake build, so the FFI fallback
  resolves on macOS (`.dylib`) instead of silently falling through to the
  pure-PHP encoder; the previously-skipped `QrReferenceTest` and `FfiEncoderTest`
  cases now run on macOS (#43)
- Applied `php-cs-fixer` and `rector` to the whole codebase: PSR-12
  formatting, `readonly` properties, removed unused promoted property in
  `FfiEncoder`
- Composer plugin (`src/Composer/Plugin.php`) now routes native binary
  downloads through `BinaryDownloader` with a `ChecksumManager`, reuses
  `PlatformDetector` instead of duplicating it, and refuses any download
  without a configured SHA-256 checksum — checksum verification is now
  mandatory (fail-closed) instead of fail-open (#48)

### Fixed

- Composer plugin no longer trusts binaries already present on disk without
  re-verification: when a SHA-256 checksum is pinned in the root
  `composer.json`, an existing extension/FFI binary whose hash does not match
  is removed and re-downloaded through the verified (fail-closed) path
  instead of being accepted as-is (#185)

### Removed

- Prebuilt **Windows** binaries. The Windows FFI job is gone from the release
  workflow, so `scanme_qr-windows-x86_64.dll` is no longer published (no
  Windows extension binary was ever built). Windows keeps working through the
  pure-PHP encoder, and a local MSVC build of `clib/` still produces a usable
  DLL; `PlatformDetector` deliberately still resolves a Windows binary name so
  the probe-then-fall-back path stays intact instead of throwing
- PHP 8.1 from the supported PHP range for the library code

## [0.4.11] - 2026-03-18

### Fixed

- `PngRenderer` now correctly respects `invert` option (#32)
- `SvgRenderer` now correctly inverts module pattern when `invert` option is enabled (#32)

## [0.4.10] - 2026-03-17

### Added

- CI builds PHP extension binaries for PHP 8.1, 8.2, 8.3, 8.4 on Linux (glibc/musl) and macOS (x86_64/arm64)
- Composer plugin now detects PHP version and downloads matching binary
- Binary naming convention includes PHP version (e.g., `php-ext-linux-glibc-x86_64-php84.so`)
- PHP version compatibility matrix in README

### Changed

- Updated release workflow to build 32 php-ext binaries (4 PHP versions × 4 platforms)

## [0.4.7] - 2026-03-17

### Added

- Composer plugin for fully automatic FFI binary installation (zero configuration)
- Plugin auto-detects platform and downloads appropriate binary on `composer install`
- Automatic fallback to pure PHP encoder when FFI is unavailable or binary download fails

### Changed

- Replaced manual post-install-cmd scripts with Composer PluginInterface
- Binary installation now requires no user configuration - works out of the box

## [0.4.6] - 2026-03-17

### Added

- Automatic FFI binary download during `composer install` based on platform detection
- `PlatformDetector` class for OS/architecture detection (Linux glibc/musl, macOS x86_64/arm64, Windows)
- `BinaryDownloader` class for downloading prebuilt binaries from GitHub releases
- `ChecksumManager` class for optional checksum verification from composer.json extra section
- `Builder` class for fallback to building from source when download fails
- `Composer\InstallScript` with post-install and post-update hooks for automatic binary management
- `DownloadException` for download-related error handling
- `SvgRenderer` now accepts optional `$moduleSize` constructor parameter (default: 10)

### Changed

- FFI binaries stored in `vendor/crazy-goat/scanmephp/ffi-binaries/` for proper isolation
- `QRCode::createDefaultEncoder()` auto-selects FFI encoder from vendor directory
- Version detection prefers git tag over composer/installed.json for GitHub releases

## [0.4.5] - 2026-03-17

### Added

- Composer post-install/post-update hooks to auto-download prebuilt FFI binaries (#23)
- `BinaryDownloader` — downloads FFI binaries from GitHub releases with checksum verification
- `ChecksumManager` — SHA256 checksum validation for downloaded binaries
- `PlatformDetector` — automatic OS and architecture detection (Linux/macOS, x86_64/ARM64, glibc/musl)
- `InstallScript` — Composer script handler with fallback support for manual download instructions
- `Builder` — CLI tool to manually trigger binary download

## [0.4.8] - 2026-03-17

### Added

- PHP extension (`php-ext/`) with `NativeEncoderExt` class for maximum performance
- `bench/benchmark_all.php` - benchmark script comparing all 4 encoders
- `encodeMatrix()` method to NativeEncoderExt for direct Matrix return type

### Changed

- Renamed PHP extension from `scanme_qr` to `scanmeqr` for consistency
- Improved NativeEncoder.php fallback and namespace handling
- Cleaned up C++ encoder code (removed unused functions and comments)

### Performance

- NativeEncoderExt: 0.053-0.880ms (13-21× faster than pure PHP)
- FfiEncoder: 0.102-1.319ms (7-11× faster than pure PHP)
- FastEncoder: 0.629-5.724ms (1.6-2× faster than pure PHP)

## [0.4.9] - 2026-03-17

### Added

- CI workflow to build and release PHP extension binaries alongside FFI library on version tag push (#26)
- `Composer\Plugin` updated to support automatic download and installation of both PHP extension and FFI library binaries
- PHP extension binaries for Linux (glibc/musl) and macOS (x86_64/arm64) in GitHub releases

### Changed

- Composer plugin now tries to install PHP extension first (13-21× faster), falls back to FFI library (10-12× faster)
- Updated README with comprehensive PHP extension installation instructions

### Fixed

- Test assertion in `InstallScriptTest::testGetPackageVersionFromComposer` to match normalized version format

## [0.3.0] - 2026-03-16

### Added

- `PngRenderer` - native 1-bit monochrome PNG renderer (pure PHP, no GD, no Imagick, no external libraries)
- `PngEncoder` - minimal PNG binary encoder (Signature + IHDR + IDAT + IEND) using `gzcompress()` and `crc32()`
- `ext-gd` added to `require-dev` for PNG validation in tests

### Fixed

- Removed `version` field from `composer.json` to pass `composer validate --strict` in CI

## [0.2.0] - 2026-03-16

### Added

- GitHub Actions CI workflow with permission checks
- Support for PHP 8.1, 8.2, 8.3, 8.4 in CI pipeline
- Automatic CI runs for repo owner and developers with write access

### Fixed

- PHP 8.1 compatibility - replaced `readonly class` with `readonly` properties

## [0.1.0] - 2026-03-16

### Added

- Pure PHP QR code encoding supporting versions 1-40 with all ECC levels (Low, Medium, Quartile, High)
- 7 built-in renderers:
  - `FullBlocksRenderer` - ASCII output using full block characters (`█`)
  - `HalfBlocksRenderer` - Compact ASCII using half-block characters (`▀▄█`)
  - `SimpleRenderer` - ASCII using dots (`●`) for terminals without Unicode block support
  - `SvgRenderer` - SVG XML output with customizable module styles
  - `HtmlDivRenderer` - HTML `<div>` flexbox grid with inline styles
  - `HtmlTableRenderer` - HTML `<table>` with `<td>` elements
- Module styles for SVG renderer: Square, Rounded, and Dot
- Label support - optional text displayed below QR code
- Custom colors support for SVG and HTML renderers (foreground and background)
- Invert/dark mode support - swap foreground and background colors
- Auto version detection - automatically selects optimal QR version based on data length
- Multiple output methods:
  - `render()` - returns string output
  - `saveToFile()` - writes to file
  - `getDataUri()` - returns data URI with base64 encoding
  - `toBase64()` - returns raw base64 string
  - `toHttpResponse()` - sends Content-Type header and outputs content
  - `getMatrix()` - returns raw Matrix object for custom processing
  - `validate()` - checks if data fits in selected QR version
  - `__toString()` - string casting support
- `RendererInterface` for creating custom renderers
- Comprehensive test suite with PHPUnit
- Full documentation and usage examples

[Unreleased]: https://github.com/crazy-goat/ScanMePHP/compare/v0.5.2...HEAD
[0.5.2]: https://github.com/crazy-goat/ScanMePHP/compare/v0.5.1...v0.5.2
[0.5.1]: https://github.com/crazy-goat/ScanMePHP/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/crazy-goat/ScanMePHP/compare/v0.4.11...v0.5.0
[0.4.11]: https://github.com/crazy-goat/ScanMePHP/compare/v0.4.10...v0.4.11
[0.4.10]: https://github.com/crazy-goat/ScanMePHP/compare/v0.4.9...v0.4.10
[0.4.9]: https://github.com/crazy-goat/ScanMePHP/compare/v0.4.8...v0.4.9
[0.4.8]: https://github.com/crazy-goat/ScanMePHP/compare/v0.4.7...v0.4.8
[0.4.7]: https://github.com/crazy-goat/ScanMePHP/compare/v0.4.6...v0.4.7
[0.4.6]: https://github.com/crazy-goat/ScanMePHP/compare/v0.4.5...v0.4.6
[0.4.5]: https://github.com/crazy-goat/ScanMePHP/compare/v0.4.4...v0.4.5
[0.3.0]: https://github.com/crazy-goat/ScanMePHP/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/crazy-goat/ScanMePHP/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/crazy-goat/ScanMePHP/releases/tag/v0.1.0
