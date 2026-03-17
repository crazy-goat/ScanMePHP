# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

## [Unreleased]

### Added

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
- PHP extension (`php-ext/`) with 13–21× performance improvement over pure PHP encoder

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

[Unreleased]: https://github.com/crazy-goat/ScanMePHP/compare/v0.4.9...HEAD
[0.4.9]: https://github.com/crazy-goat/ScanMePHP/compare/v0.4.8...v0.4.9
[0.4.8]: https://github.com/crazy-goat/ScanMePHP/compare/v0.4.7...v0.4.8
[0.4.7]: https://github.com/crazy-goat/ScanMePHP/compare/v0.4.6...v0.4.7
[0.4.6]: https://github.com/crazy-goat/ScanMePHP/compare/v0.4.5...v0.4.6
[0.4.5]: https://github.com/crazy-goat/ScanMePHP/compare/v0.4.4...v0.4.5
[0.3.0]: https://github.com/crazy-goat/ScanMePHP/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/crazy-goat/ScanMePHP/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/crazy-goat/ScanMePHP/releases/tag/v0.1.0
