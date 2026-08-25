# Performance pass 2026-08 — before / after

Branch `perf/2026-08-speed-pass` (4 commits: `perf(clib)`, `perf(php)`,
`perf(native)`, `perf(render)`) measured against its base `9ca32d5` (`main`).
Both trees were benchmarked with the same script on the same machine, one
after the other, each in its own PHP process with its own C++ library and
extension build.

## Environment and method

- PHP 8.5.9 (NTS, 64-bit), `opcache.enable_cli=1`, `opcache.jit=tracing`,
  `opcache.jit_buffer_size=64M`; ext-gmp loaded (PNG bit packing takes the
  GMP path; the `bindec()` fallback is ~25 % slower for PNG, see below)
- Apple M-series (arm64); C++ library built `Release` with the `generic`
  (compiler-vectorised) mask kernel
- `bench/benchmark_e2e.php`: every case is warmed up, then run for ≥ 15 and
  ≤ 3 000 iterations (~0.4 s budget); the table shows the mean in µs. QR
  versions are read from the produced matrix, not assumed from payload size.
  Payloads are `https://example.com/` repeated to 10 / 100 / 260 / 840 /
  1440 / 2900 bytes → v1 / v5 / v10 / v20 / v27 / v40, ECL L
- Output equivalence: ASCII and HTML are byte-identical before/after; PNG is
  pixel-identical (checked with GD at zlib levels 0/1/6/9); SVG Square style
  rasterises to identical pixels under `rsvg-convert` (0 differing pixels,
  v10 and v27, normal and inverted); SVG Rounded/Dot emit the same set of
  elements in a different order

## 1. Components

### 1.1 C++ library (`clib`, µs per symbol, `scanme_bench 500`, p50)

| Version | before | after | speed-up |
|---|---|---|---|
| v1 L | 21 | **1.6** | 13× |
| v10 L | 68 | **7.0** | 10× |
| v25 L | — | **30** | |
| v40 L | 1 276 | **79** | 16× |

Lane-parallel evaluation of all 8 mask penalties (`mask_kernel.hpp`,
compiled per ISA with runtime dispatch), 4×uint64 Reed–Solomon, branch-free
placement, table-driven module expansion. ("before" for the C++ core was
measured with a scratch benchmark at the start of the pass; the `scanme_bench`
target did not exist yet.)

### 1.2 PHP encoders (`encode()` only, µs)

| Encoder | v1 | v5 | v10 | v20 | v27 | v40 |
|---|---|---|---|---|---|---|
| `Encoder` (portable) | 148 → **19** (7.8×) | 566 → **34** (17×) | 1 437 → **64** (22×) | 4 691 → **241** (19×) | 7 877 → **375** (21×) | 15 875 → 15 809 (1.0×) |
| `FastEncoder` (64-bit) | 38 → **16** (2.4×) | 91 → **36** (2.5×) | 195 → **63** (3.1×) | 750 → **238** (3.2×) | 1 392 → **372** (3.7×) | n/a |
| `FfiEncoder` (C++ via FFI) | 27 → **2.5** (11×) | 76 → **4.1** (19×) | 179 → **8.0** (22×) | 630 → **25** (25×) | 1 121 → **39** (29×) | 2 533 → **86** (30×) |
| `NativeEncoderExt` (php-ext) | 14 → **2.0** (7×) | 38 → **3.6** (10×) | 91 → **6.8** (13×) | 345 → **23** (15×) | 642 → **34** (19×) | 1 509 → **77** (20×) |

- `Encoder` now delegates Byte-mode v ≤ 27 to `FastEncoder::encodeVersion()`
  (same output), hence identical numbers. **v28–v40 still run the old scalar
  pipeline and were not touched** — that is the one row without a gain.
- The native encoders gained from three places: the C++ core (above), no
  longer building a size² PHP array at the boundary (`Matrix::fromModuleString`,
  ~2 µs at v10 / ~35 µs at v40 for ext; ~16 µs at v10 for FFI's
  `unpack()`+`array_values()`), and — for FFI — a single `strtr()` instead
  of per-module conversion. Ext and FFI now differ by < 1 µs.

### 1.3 Renderers (`render()` only, matrix from the extension, margin 4, moduleSize 10, µs)

| Renderer | v1 | v10 | v27 | v40 |
|---|---|---|---|---|
| `FullBlocksRenderer` | 6.7 → **2.3** (2.9×) | 47 → **13.5** (3.5×) | 232 → **74** (3.1×) | 473 → **162** (2.9×) |
| `HalfBlocksRenderer` | 12.5 → **2.1** (6×) | 85 → **10.6** (8.1×) | 403 → **56** (7.2×) | 826 → **129** (6.4×) |
| `SimpleRenderer` | 6.6 → **2.1** (3.1×) | 47 → **13.2** (3.5×) | 235 → **72** (3.3×) | 472 → **164** (2.9×) |
| `HtmlDivRenderer` | 63 → **7.5** (8.4×) | 332 → **45** (7.4×) | 1 363 → **199** (6.9×) | 2 796 → **953** (2.9×) |
| `HtmlTableRenderer` | 64 → **9.4** (6.8×) | 338 → **43** (7.8×) | 1 409 → **314** (4.5×) | 2 886 → **786** (3.7×) |
| `SvgRenderer` Square | 48 → **16** (3.0×) | 251 → **80** (3.1×) | 1 166 → **376** (3.1×) | 2 391 → **715** (3.3×) |
| `SvgRenderer` Rounded | 66 → **17.5** (3.8×) | 470 → **127** (3.7×) | 2 334 → **623** (3.7×) | 4 924 → **1 507** (3.3×) |
| `SvgRenderer` Dot | 54 → **18** (3.0×) | 344 → **128** (2.7×) | 1 658 → **584** (2.8×) | 3 321 → **1 214** (2.7×) |
| `PngRenderer` | 608 → **25** (24×) | 3 161 → **123** (26×) | 14 181 → **633** (22×) | 27 635 → **1 286** (21×) |

All renderers now take the symbol as a `'0'/'1'` module string
(`Matrix::toModuleString()`, cached) and work on it with C-level string
functions — `substr`/`strtr`/`str_replace`/`preg_match_all` — instead of one
`Matrix::get()` call plus `sprintf()`/concatenation per module. HTML at v40
is bound by copying its ~3 MB output; SVG Rounded/Dot still emit one element
per module.

Output size where it changed:

| Renderer | v1 | v10 | v27 | v40 | why |
|---|---|---|---|---|---|
| `SvgRenderer` Square | 15.9 → **9.4 KB** | 103 → **22 KB** | 500 → **82 KB** | 1 014 → **159 KB** | horizontal runs of dark modules merged into one `<path>`; finder patterns keep their rounded `<rect>`s |
| `PngRenderer` | 322 → 493 B | 1.4 → 2.4 KB | 5.7 → 9.5 KB | 11.0 → 17.7 KB | repeated scanlines stored with the PNG *Up* filter and zlib level **1** by default (7× faster than level 6 at v10: 31 vs 206 µs); `new PngRenderer(compressionLevel: 6)` gives the old size |

Without ext-gmp the PNG numbers are ~20–30 % higher (v10 123 → 157 µs,
v27 633 → 731 µs); everything else is unaffected.

## 2. End to end — `(new QRCode($url, $config, $encoder))->render()`

Includes object construction, encoding, `RenderOptions` and rendering, µs.

### 2.1 Pure PHP (`Encoder`) — what you get with no native binaries

| Renderer | v1 | v10 | v27 |
|---|---|---|---|
| `HalfBlocksRenderer` | 138 → **20** (6.8×) | 1 517 → **97** (16×) | 8 164 → **531** (15×) |
| `SvgRenderer` | 172 → **34** (5.0×) | 1 679 → **164** (10×) | 8 890 → **836** (11×) |
| `HtmlDivRenderer` | 189 → **26** (7.2×) | 1 737 → **131** (13×) | 8 944 → **964** (9.3×) |
| `PngRenderer` | 717 → **44** (16×) | 4 799 → **210** (23×) | 21 889 → **1 111** (20×) |

### 2.2 Native (`NativeEncoderExt`, the auto-selected tier when the extension is loaded)

| Renderer | v1 | v10 | v27 |
|---|---|---|---|
| `HalfBlocksRenderer` | 26 → **4.3** (6.0×) | 181 → **18.5** (9.8×) | 1 037 → **94** (11×) |
| `SvgRenderer` | 61 → **17.7** (3.5×) | 342 → **89** (3.8×) | 1 787 → **406** (4.4×) |
| `HtmlDivRenderer` | 78 → **10.2** (7.6×) | 416 → **54** (7.7×) | 1 980 → **540** (3.7×) |
| `PngRenderer` | 605 → **28** (22×) | 3 299 → **131** (25×) | 15 191 → **670** (23×) |

Reading the two tables together: before the pass a v10 code cost 1.5–4.8 ms
in pure PHP and 0.2–3.3 ms with the extension; now it is 0.10–0.21 ms in pure
PHP and 0.02–0.13 ms with the extension. Encoding is no longer the dominant
cost anywhere — at v10 it is 64 µs (PHP) or 7 µs (ext) of those totals; the
rest is rendering and, for PNG, `gzcompress()`.

## 3. What did not change / known limits

- `Encoder` v28–v40 (scalar pipeline): 16 ms at v40, untouched. Use
  `FfiEncoder`/`NativeEncoderExt` (77–86 µs) for large symbols.
- SVG Rounded/Dot styles are still one element per module; the Square path
  trick does not apply to them.
- The x86-64 AVX2/AVX-512 mask kernels were validated for correctness only
  (Rosetta locally; AVX2 also on the CI runner). Their speed on real x86
  hardware is unmeasured, and AVX-512 cannot run on CPUs that lack it — the
  CI step reports such a kernel as `SKIP` rather than crashing. The numbers
  above are the arm64 `generic` kernel.
- `HtmlTableRenderer`/`HtmlDivRenderer` at v27+ are limited by output size
  (1–3 MB of markup), not by module processing.

## Reproducing

```sh
# current tree
cd php-ext && make && cd ..
cmake -B clib/build -DCMAKE_BUILD_TYPE=Release -DBUILD_BENCH=ON && cmake --build clib/build
php -d extension=php-ext/modules/scanmeqr.so -d opcache.enable_cli=1 \
    -d opcache.jit_buffer_size=64M -d opcache.jit=tracing \
    bench/benchmark_e2e.php . after          # writes after.csv
clib/build/bench/scanme_bench 500

# base commit, in a worktree with its own builds
git worktree add /tmp/before 9ca32d5 && cp -R vendor /tmp/before/
(cd /tmp/before && cmake -B clib/build -DBUILD_TESTS=OFF && cmake --build clib/build \
   && cd php-ext && phpize && ./configure && make)
php -d extension=/tmp/before/php-ext/modules/scanmeqr.so -d opcache.enable_cli=1 \
    -d opcache.jit_buffer_size=64M -d opcache.jit=tracing \
    bench/benchmark_e2e.php /tmp/before before  # writes before.csv
```

`bench/benchmark_e2e.php <root> <label>` prints every case to stderr and
writes `<label>.csv` (`section,name,version,us,bytes`).
