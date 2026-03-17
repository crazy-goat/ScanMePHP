# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `SvgRenderer` now accepts optional `$moduleSize` constructor parameter (default: 10)
- `InvalidConfigurationException` for configuration validation errors
- Native C++ QR encoder library (`clib/`) with SIMD acceleration (SSE2, SSE4.2, AVX2, AVX-512, NEON, scalar fallback)
- `EncoderInterface` extracted from `Encoder` for dependency injection
- `FfiEncoder` — PHP FFI bridge to the native C++ library, producing byte-for-byte identical output to `Encoder`
- `FastEncoder` — 64-bit PHP encoder with int-pair packed matrix, ~2× faster than portable Encoder
- `QRCode::createDefaultEncoder()` — auto-selects `FfiEncoder` when available, falls back to `FastEncoder`, then `Encoder`
- `QRCode` constructor now accepts optional `EncoderInterface $encoder` parameter
- `ReedSolomon::encodeWithInterleaving()` — multi-block RS interleaving for correct ECC across all QR versions
- Reference test suite — 1772 test cases × 2 encoders (FastEncoder + FfiEncoder) verified against nayuki's QR Code generator
- CI workflow to automatically build and release FFI library binaries for Linux (glibc/musl) and macOS (x86_64/ARM64) on version tag push (#22)

### Changed

- **Performance optimizations across all renderers (20-40% improvement):**
  - `SvgRenderer`: Direct string concatenation instead of array+implode
  - `HtmlDivRenderer` & `HtmlTableRenderer`: Eliminated sprintf() in tight loops
  - `FullBlocksRenderer`, `HalfBlocksRenderer`, `SimpleRenderer`: Direct output instead of array buffering
  - `PngRenderer` + `PngEncoder`: Streaming scanline generation (major memory reduction)
- Removed `docs/` directory from repository tracking and added to `.gitignore`
- **Encoder performance improved 7–8× (5–58 ms → 0.7–7.7 ms)** by adopting nayuki's penalty algorithm

### Fixed

- Multi-block Reed-Solomon interleaving — all encoders were treating data as a single block instead of splitting into per-block slices per EC_BLOCKS table
- Penalty Rule 3 (finder pattern detection) — replaced naive pattern matching with nayuki's run-history algorithm
- Penalty Rule 4 (dark/light balance) — replaced floating-point formula with nayuki's integer formula
- Reserved module masking — MaskSelector now only masks data modules, not finder patterns, timing, etc.
- FastEncoder namespace corrected from `ScanMePHP` to `CrazyGoat\ScanMePHP`
- 13 incorrect entries in C++ EC_TABLE corrected
- C++ `place_version_info()` coordinate transposition fixed
- C++ mask tile y-period fixed (`y % 12` instead of `y % 6`)

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

[Unreleased]: https://github.com/crazy-goat/ScanMePHP/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/crazy-goat/ScanMePHP/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/crazy-goat/ScanMePHP/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/crazy-goat/ScanMePHP/releases/tag/v0.1.0
