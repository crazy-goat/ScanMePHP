# ScanMePHP - Agent Guidelines

Pure PHP QR code generator with zero dependencies. PHP 8.2+.

## Build & Test Commands

```bash
# Install dependencies
composer install

# Run all tests
composer test            # OR: vendor/bin/phpunit

# Run a single test method / file
vendor/bin/phpunit --filter testBasicAsciiQrCode
vendor/bin/phpunit tests/QRCodeTest.php

# Validate composer files
composer validate --strict

# Lint: PHP-CS-Fixer, Rector, PHPStan, clang-format, shellcheck, hadolint
bin/lint.sh              # check only, runs every step, non-zero on any failure
bin/lint.sh --fix        # apply fixes first, then check
composer lint            # same as bin/lint.sh (composer lint-fix = --fix)
```

`bin/lint.sh` needs `clang-format` 23.1.2, `shellcheck` and `hadolint` on the `PATH`; a missing
tool is a failure. CI installs pinned versions (see `.github/workflows/ci.yml`).

### C++ library and PHP extension

The PHP tests fall back to the pure-PHP encoder when no native build exists. Build these only
when you change `clib/` or `php-ext/`; CI builds and tests both:

```bash
# C++ FFI library (clib/) and its tests, also used by the FFI tests in tests/
cmake -S clib -B clib/build -DCMAKE_BUILD_TYPE=Release -DBUILD_TESTS=ON -DBUILD_BENCH=ON
cmake --build clib/build -j && (cd clib/build && ctest --output-on-failure)

# PHP extension (php-ext/), built with phpize
(cd php-ext && phpize && ./configure && make -j && make test TESTS=tests NO_INTERACTION=1)
php -d extension=php-ext/modules/scanmeqr.so vendor/bin/phpunit

# Assemble and build the qrcode-ext (PIE) mirror in build/ext-mirror
bash bin/build-ext-mirror.sh
```

C and C++ sources are formatted with `.clang-format` (whitespace only; `bin/lint.sh --fix`
applies it). `php-ext/` and `clib/` are the only places these sources are edited:
`crazy-goat/qrcode-ext` is generated from them by `bin/build-ext-mirror.sh`.

## Code Style Guidelines

### PHP Version & Strict Types
- **PHP 8.2+ required** - use modern features
- Always start files with: `<?php\ndeclare(strict_types=1);`
- Use constructor property promotion
- Use readonly properties where appropriate
- Use enums for fixed value sets

### Naming Conventions
- **Classes/Interfaces/Enums**: PascalCase (e.g., `QRCode`, `RendererInterface`)
- **Methods/Properties**: camelCase (e.g., `render()`, `errorCorrectionLevel`)
- **Enum Cases**: PascalCase (e.g., `ErrorCorrectionLevel::Medium`)
- **Constants**: No constants used - prefer enums
- **Namespaces**: `ScanMePHP\` for src, `ScanMePHP\Tests\` for tests

### Imports & Organization
- Group use statements together (no blank lines between)
- Order: core PHP, then project namespaces
- No unused imports
- Example:
  ```php
  use ScanMePHP\Encoding\Mode;
  use ScanMePHP\Exception\FileWriteException;
  use ScanMePHP\Exception\InvalidDataException;
  ```

### Type Declarations
- Always declare return types
- Use nullable types: `?string`, `?QRCodeConfig`
- Use `void` for methods that don't return
- Use `never` for methods that always exit (e.g., `toHttpResponse(): never`)
- Use union types where appropriate (PHP 8.0+)

### Error Handling
- Create custom exceptions in `src/Exception/`
- Use static factory methods on exceptions:
  ```php
  throw InvalidDataException::emptyData();
  throw FileWriteException::directoryNotWritable($directory);
  ```
- Use `sprintf()` for formatted messages in exceptions
- Catch with `\Exception` when type doesn't matter

### Class Structure
- Properties first (private, typed)
- Constructor with property promotion preferred
- Public methods follow
- Private helper methods last
- No docblocks unless complex logic requires explanation

### Testing
- Tests extend `PHPUnit\Framework\TestCase`
- Test methods: `testDescriptiveName(): void`
- Use try/finally for temp file cleanup
- Use `assertStringContainsString`, `assertIsString`, etc.
- Test file naming: `ClassNameTest.php`

### Zero Dependencies Principle
- **NO external runtime dependencies** (except PHPUnit for dev)
- **NO PHP extensions required** (except ext-gd for dev/testing)
- Implement everything in pure PHP
- All renderers (PNG, SVG, HTML, ASCII) are pure PHP implementations

### Architecture Patterns
- Renderers implement `RendererInterface`
- Config uses immutable readonly properties via `QRCodeConfig`
- Matrix encoding in `Encoding/` namespace
- Enums for: `ErrorCorrectionLevel`, `ModuleStyle`, `Mode`
- Renderers in `Renderer/` subdirectory

## Project Structure

```
src/
  Renderer/          # Output format implementations
  Encoding/           # QR encoding logic
  Exception/          # Custom exceptions
  *.php               # Main classes, interfaces, enums
tests/
  *Test.php           # PHPUnit tests
clib/                 # C++ core, FFI library and C++ tests
php-ext/              # PHP extension (also the source of crazy-goat/qrcode-ext)
bin/                  # lint, worktree and release helper scripts
docs/                 # workflow and release process
examples/             # Usage examples
```

## CI/CD

GitHub Actions (`.github/workflows/ci.yml`) runs on pull requests and pushes to `main`:
`changes` (skips the heavy jobs for documentation-only changes), `docs`, `lint`
(`bin/lint.sh`) and `test` on PHP 8.2, 8.3 and 8.4 (C++ tests, extension build, PHPUnit).
The required check is `ci-ok`. `release-build.yml` builds the binaries and creates the GitHub
Release when a `v*` tag is pushed.

## Workflow

The development process (issue, worktree, code, review, PR, CI, merge, findings, cleanup) is
in [docs/workflow.md](docs/workflow.md); releases are in
[docs/release-workflow.md](docs/release-workflow.md). Short version:

- `bin/pick-issue.sh` picks an issue, `bin/worktree.sh <issue>` creates the worktree
  (`bin/worktree-setup.sh` runs `composer install`), `bin/worktree-done.sh <issue>` cleans up.
- **Never push directly to `main`.** One issue, one worktree, one branch, one pull request,
  squash merge once `ci-ok` is green.
- Commits and PR titles are Conventional Commits, for example `fix: handle empty data (#42)`.
- Everything is written in English: code, comments, commits, docs, issues.

### CHANGELOG Rules
- Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
- Sections: `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`
- Every PR that changes user-visible behaviour must have a CHANGELOG entry under `## [Unreleased]`
- On release the entries move to the new version section; see
  [docs/release-workflow.md](docs/release-workflow.md)
- `version` field must NOT be present in `composer.json` (Packagist uses git tags)
