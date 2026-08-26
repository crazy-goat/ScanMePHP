# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
