# ScanMePHP — Encoder Benchmark Results

Benchmark comparing four encoder implementations across QR versions 1–40.

- **Encoder (portable)** — v1–v40, any 64-bit PHP 8.2+. Byte-mode symbols up to v27 take the same bitset fast path as `FastEncoder` (identical output); v28–v40 use the readable scalar pipeline
- **FastEncoder (64-bit)** — monolithic int-packed encoder using int-pair `[$hi, $lo]` rows *and* columns, v1–v27, Byte mode only; penalty rules evaluated bitwise on whole lines
- **FfiEncoder (native C++)** — C++20 library via PHP FFI, SIMD mask kernel, precomputed RS factor tables, v1–v40, Byte mode only
- **NativeEncoderExt (php-ext)** — C extension using clib, v1–v40, Byte mode only, requires extension loaded

## Environment

- PHP 8.5 (64-bit, NTS), `opcache.jit=tracing`, Apple M-series (arm64, NEON mask kernel)
- `bench/benchmark_all.php 500` — 500 iterations per test, warmup + gc_collect_cycles()
- All times in milliseconds (lower is better)

## Results (p50 — median latency)

| Version     | Encoder (portable) | FastEncoder (64-bit) | FfiEncoder (C++) | NativeEncoderExt | PHP/FFI | PHP/Ext |
|-------------|--------------------|----------------------|------------------|------------------|---------|---------|
| v1 L        | 0.016 ms           | 0.015 ms             | 0.003 ms         | **0.002 ms**     | 5×      | **7×**  |
| v5 L        | 0.031 ms           | 0.031 ms             | 0.004 ms         | **0.004 ms**     | 8×      | **8×**  |
| v10 L       | 0.061 ms           | 0.060 ms             | 0.008 ms         | **0.009 ms**     | 7.5×    | **7×**  |
| v12 M       | 0.116 ms           | 0.115 ms             | 0.014 ms         | **0.016 ms**     | 8×      | **7×**  |
| v20 L       | 0.195 ms           | 0.200 ms             | 0.025 ms         | **0.030 ms**     | 8×      | **6.5×** |
| v27 L       | 0.310 ms           | 0.310 ms             | 0.039 ms         | **0.046 ms**     | 8×      | **6.7×** |

Before the 2026-08 pass the pure-PHP encoders took 0.425 / 1.366 / 3.168 ms
for v1 L / v5 M / v10 L (`Encoder`) and 0.233 / 0.662 / 1.465 ms
(`FastEncoder`), i.e. the pure-PHP path is now 20–50× faster and the native
encoders' lead shrank from 90–360× to 3–7×.

Without the JIT (`opcache.jit=off`) the pure-PHP numbers are ~4× higher
(v1 72 µs, v10 260 µs, v20 860 µs); the native encoders are unaffected.

## Key Takeaways

- **NativeEncoderExt and FfiEncoder are now equally fast at encoding** — 6–8× faster than pure PHP, a v10 code in ~8–9 µs, within ~2 µs of the bare C++ library
- **Encoder and FastEncoder are now the same speed** for Byte-mode v1–v27: `Encoder` delegates to the bitset fast path and only runs its scalar pipeline for v28–v40
- The PHP boundary is what separates the two native tiers. Building a size² zval array costs ~3 µs at v10 and ~35 µs at v40, and `unpack()` + `array_values()` in FFI cost ~16 µs at v10. Both native encoders now hand the C library's bytes to `Matrix::fromModuleString()` as a `'0'/'1'` string (FFI: one `strtr()`, ext: one C loop), so FFI encode dropped 3× (v10 24 → 8 µs, v27 113 → 39 µs) and ext another 2 µs (v10 9 → 7 µs)
- The `'0'/'1'` module string is also what the renderers consume (`Matrix::toModuleString()`, see below), so a string-backed matrix is the cheapest to render as well; pure-PHP matrices build the string once (`implode()` of the `unpack()` ints, ~12 µs at v10) and cache it until the next write
- All four encoders produce byte-for-byte identical output, verified against nayuki's reference implementation (1772 test cases × 4 encoders), a 1920-matrix mask-penalty equivalence test in `clib/tests`, and random cross-checks PHP ↔ C++ for v1–v40

## Renderers

Rendering used to dwarf encoding: one `Matrix::get()` call per module plus
per-module `sprintf()`/concatenation. The renderers now take the whole symbol
as a `'0'/'1'` module string and use C-level string functions on it — a row is
a `substr()`, glyphs and HTML cells are a `strtr()`/`str_replace()`, runs of
dark modules are one `preg_match_all()`, PNG pixel bits are packed by GMP (or
`bindec()` in 56-bit chunks). Same environment as above, µs per render,
`margin: 4`, `moduleSize: 10`, before = `main` at 9ca32d5 (full before/after
report incl. end-to-end numbers: `OPTIMIZATION_RESULTS_2026-08.md`):

The renderer names below are the ones the classes had at the time. They are now
reached by format name through `Scanme::render()` — `FullBlocksRenderer` is
`ascii-blocks`, `HalfBlocksRenderer` is `ascii-half-blocks`, `SimpleRenderer` is
`ascii-dots`, and the rest keep the obvious names. The measurements are
unchanged: the same code, renamed.

| Renderer | v10 before | v10 after | v27 before | v27 after | Output |
|---|---|---|---|---|---|
| `FullBlocksRenderer` | 47 | **13.5** | 232 | **74** | identical |
| `HalfBlocksRenderer` | 85 | **10.6** | 403 | **56** | identical |
| `SimpleRenderer` | 47 | **13.2** | 235 | **72** | identical |
| `HtmlDivRenderer` | 332 | **45** | 1 363 | **199** | identical |
| `HtmlTableRenderer` | 338 | **43** | 1 409 | **314** | identical |
| `SvgRenderer` (Square) | 251 | **80** | 1 166 | **376** | one `<path>` of horizontal runs, 103 → 22 KB (v10) |
| `SvgRenderer` (Rounded) | 470 | **127** | 2 334 | **623** | same elements, different order |
| `SvgRenderer` (Dot) | 344 | **128** | 1 658 | **584** | same elements, different order |
| `PngRenderer` | 3 161 | **123** | 14 181 | **633** | same pixels; 1.4 → 2.4 KB at the new default zlib level 1 |

- **SVG**: Square modules are merged per row into runs and emitted as one `<path d="M… h… v… h-… z …">` — abutting sub-paths of a single path rasterise without anti-aliasing seams, and `rsvg-convert` renders the old and new files to identical pixels. Finder patterns keep their per-module rounded `<rect>`s in every style
- **PNG**: only the first of the `moduleSize` identical scanlines of a module row is stored raw; the rest use the PNG *Up* filter and are all zeros, which deflate handles almost for free. With that, zlib level 1 (new default; `new PngOptions(compressionLevel: 6)` restores the old size) is 7× faster than level 6 at v10 (31 vs 206 µs) for a 2.4 vs 1.5 KB file
- **HTML**: `HtmlTableRenderer` at v27 is bound by copying its 1.2 MB output, not by module work

## Pure PHP (`FastEncoder`, and `Encoder` for v ≤ 27)

Where the time goes for a v10 symbol (60 µs, JIT): mask selection ~55 %,
data placement ~15 %, Reed–Solomon ~10 %, module expansion ~10 %.

- **Mask selection** keeps every row *and* every column as one 64-bit int
  (or an int pair for v12–v27) and evaluates penalty rules 1 and 3 with the
  same bit tricks as the C++ kernel — run-of-5 marks, an 11-bit finder-pattern
  template, bitwise anchors for the wider (n ≥ 2) patterns — so a 57-module
  line costs ~50 integer ops instead of a 57-iteration loop with a 7-entry run
  history. Rule 2 (2×2 blocks) and rule 4 (dark count) are bitwise too.
  Popcounts are SWAR, reduced to 16-bit fields and accumulated across lines,
  folded once per mask. Before: 8 masks × 2 directions × size² module visits
  (52 k iterations for v10) — ~90 % of the encoder's time.
- **Reed–Solomon** holds each block's remainder in four packed 64-bit words:
  one byte-shift and four XORs per data byte instead of `array_shift()` plus
  a per-coefficient loop.
- **Data placement** walks the codeword stream a byte at a time (zero bytes
  skipped) and, for v1–v11, only touches the `lo` words.
- **Module expansion** goes int rows → "\0"/"\1" string via a 256-entry
  table → `unpack('C*')`; `Matrix` normalises to `bool[]` lazily.
- The 128-bit (v12–v27) path costs ~2× per line because every shift crosses
  the int pair. An alternative with overlapping 64-bit windows was measured
  and rejected: slower for every version (loop overhead and a third window
  above v21 outweigh the saved shifts).

## C++ library (`clib/`) alone

`clib/bench/scanme_bench` times `scanme_qr_encode_matrix()` without PHP and
breaks the cost down per pipeline stage. Same machine, 1000 iterations, p50:

| Version | ECL | Latency | codewords | RS + interleave | placement | mask selection | apply |
|---------|-----|---------|-----------|-----------------|-----------|----------------|-------|
| v1      | L   | 1.5 µs  | 0.01      | 0.05            | 0.32      | 1.05           | 0.03  |
| v10     | M   | 6.4 µs  | 0.07      | 0.60            | 1.60      | 3.72           | 0.05  |
| v25     | L   | 30 µs   | 0.34      | 3.14            | 5.63      | 17.98          | 0.06  |
| v40     | L   | 80 µs   | 0.81      | 7.61            | 13.22     | 51.99          | 0.12  |

(before the 2026-08 optimisation pass: v1 21 µs, v10 68 µs, v40 1276 µs — mask
selection alone was 1.2 ms for v40)

How the hot paths work:

- **Mask selection** evaluates all 8 masks at once: rows are stored as
  `w[word][mask]` so every operation is lane-wise over the 8 masks and the
  compiler vectorises it (NEON / AVX2 / AVX-512 — one 512-bit register per
  row word). Penalty rules 1 and 3 are bitwise (run-of-5 marks, an 11-bit
  finder-pattern template matched with shifts) instead of per-module scans;
  wider (n ≥ 2) finder patterns are located bitwise and verified locally. The
  row loop is templated on the number of 64-bit words per row (1 for v1–v11,
  2 for v12–v27, 3 above).
- **x86-64 runtime dispatch**: the kernel is compiled three times
  (baseline SSE2, AVX2+POPCNT+BMI2, AVX-512+VPOPCNTDQ) and the widest ISA the
  CPU supports is chosen at first use. One binary runs well on any VPS CPU.
  `SCANME_MASK_KERNEL=generic|avx2|avx512` forces a kernel (tests/bench only).
- **Reed–Solomon** keeps the remainder in four `uint64` words (one byte-shift +
  one 256-bit XOR per data byte) with factor tables cached per `ec_count`.
- **Data placement** is branch-free: two modules per row via 4×4 lookup
  tables driven by the function-module bits and the next two stream bits.
- The result is expanded to bytes 8 modules at a time via a 256-entry table.

```bash
cmake -B clib/build -S clib -DCMAKE_BUILD_TYPE=Release -DBUILD_TESTS=ON -DBUILD_BENCH=ON
cmake --build clib/build -j
ctest --test-dir clib/build            # fixtures + mask-penalty equivalence
clib/build/bench/scanme_bench 1000     # per-version latency + stage breakdown
clib/build/bench/scanme_bench 1000 csv # machine-readable
```

## Architecture

```
QrGenerator
  └── BackendSelector (picks the highest-priority available backend)
        ├── native   (400) v1-v40, C extension, requires scanmeqr.so
        ├── ffi      (300) v1-v40, C++ via FFI, requires libscanme_qr.so
        ├── bitset   (200) v1-v27, Byte mode, int-pair packed, 64-bit PHP
        └── portable (100) v1-v40; v1-v27 share the bitset path, v28-v40 scalar
```

Each backend wraps one of `NativeEncoder`, `FfiEncoder`, `FastEncoder` and
`Encoder` — all four implement `EncoderInterface` and produce identical,
spec-compliant QR codes. Selection happens at runtime per generator, so the
same code runs everywhere and simply goes faster where the binary is present:

```php
$qr = new QrGenerator();
$qr->getActiveBackend()?->getName();           // which one won here
$qr->getBackendSelector()->force('portable');  // pin one, for a benchmark
```

The other six symbologies have a single pure-PHP backend each.

## Running the Benchmark

```bash
# Benchmark all 4 encoders (requires php-ext loaded)
php -d extension=./php-ext/modules/scanmeqr.so bench/benchmark_all.php 500

# Benchmark 3 encoders (without php-ext)
php bench/benchmark_encoder.php          # 200 iterations, table output
php bench/benchmark_encoder.php 500      # 500 iterations

# Renderers, over an already-encoded symbol
php bench/benchmark_render.php all 200            # every format, one QR symbol
php bench/benchmark_render.php svg 500 1400       # one format, a 1400-byte payload
php bench/benchmark_render.php png 200 300 ean13  # a different symbology
```
