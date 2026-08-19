# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Agent workflow (`.workflow/workflow.md`) adapted from the workerman-bundle
  workflow: issue → feature branch → subagent implementation → review rounds
  → PR → CI → merge, with proof-of-work files under `.workflow/proof_of_work/`
  and a knowledge base under `.workflow/helpers/` (`faq.md`, `decisions.md`)
- Workflow helper scripts in `bin/`: `gh-branch` (derive a feature branch
  from an issue), `pick-issue.php` (rank open issues by labels/age/comments),
  `kb-lint.php` (validate the knowledge base and regenerate its tag index)
- Dev tooling via `composer lint` / `composer lint-fix`: PHPStan (level 4
  with a baseline), php-cs-fixer (PSR-12), Rector (PHP 8.2+ modernizing rules)
- `composer/composer` as a dev dependency so PHPStan can resolve the
  Composer plugin interfaces in `src/Composer/`
- Docker test image (`docker/Dockerfile`) and wrapper script (`docker/test.sh`) to run the test suite on a supported PHP version (8.4 by default) without changing the system PHP; mirrors the CI environment (ffi + gd extensions, composer, C++ build tools for `clib/`)

### Changed

- Minimum PHP raised from 8.1 to 8.2 (`composer.json`, CI matrix). The
  precompiled extension binaries are still built for 8.1 in `release-build.yml`
- CI test matrix is now PHP 8.2, 8.3, 8.4 (8.1 dropped)

### Fixed

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

### Removed

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

[Unreleased]: https://github.com/crazy-goat/ScanMePHP/compare/v0.4.11...HEAD
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
