# ScanMePHP - Agent Guidelines

Pure PHP barcode library with zero dependencies, plus optional native C++
acceleration for QR. PHP 8.2+.

## Build & Test Commands

```bash
# Run all tests
composer test
# OR
vendor/bin/phpunit

# Run a single test method
vendor/bin/phpunit --filter testTheFamilyIsRegisteredAndDescribesItself

# Run tests from a specific file
vendor/bin/phpunit tests/ScanmeTest.php

# Run with coverage (if xdebug installed)
vendor/bin/phpunit --coverage-text

# Lint: php-cs-fixer, PHPStan, Rector, knowledge-base lint — all four must pass
composer lint
composer lint-fix

# Round-trip every symbology through an independent decoder (zxing-cpp)
composer decoders:install
composer test:roundtrip

# Validate composer files
composer validate --strict

# Install dependencies
composer install
```

## Code Style Guidelines

### PHP Version & Strict Types
- **PHP 8.2+ required** - use modern features
- Always start files with: `<?php\ndeclare(strict_types=1);`
- Use constructor property promotion
- Use readonly properties where appropriate
- Use enums for fixed value sets

### Naming Conventions
- **Classes/Interfaces/Enums**: PascalCase (e.g., `Scanme`, `RendererInterface`)
- **Methods/Properties**: camelCase (e.g., `render()`, `errorCorrectionLevel`)
- **Enum Cases**: PascalCase (e.g., `ErrorCorrectionLevel::Medium`)
- **Constants**: No constants used - prefer enums
- **Namespaces**: `CrazyGoat\ScanMePHP\` for src, `CrazyGoat\ScanMePHP\Tests\` for tests

### Imports & Organization
- Group use statements together (no blank lines between)
- Order: core PHP, then project namespaces
- No unused imports
- Example:
  ```php
  use CrazyGoat\ScanMePHP\Encoding\Mode;
  use CrazyGoat\ScanMePHP\Exception\FileWriteException;
  use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
  ```

### Type Declarations
- Always declare return types
- Use nullable types: `?string`, `?RenderOptionsInterface`
- Use `void` for methods that don't return
- Use union types where appropriate (PHP 8.0+)

### Error Handling
- Create custom exceptions in `src/Exception/`
- Use static factory methods on exceptions:
  ```php
  throw UnsupportedDataException::forSymbology($title, $description);
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
- `Scanme` is the only entry point callers use; it resolves names, routes option
  bags by the interface they implement, and refuses pairs it cannot draw
  faithfully
- `Registry` holds generators and renderers by name; `Defaults::registry()` is
  what ships. Nothing in it is privileged — a registration under an existing
  name replaces it
- Generators implement `GeneratorInterface` and publish `GeneratorCapabilities`;
  renderers implement `RendererInterface` and publish `RendererCapabilities`.
  `Compatibility::check()` matches the two and reports mismatches by name rather
  than emitting a symbol that does not scan
- A generator composes a `BackendSelector` over one or more `BackendInterface`
  encoders (QR has four: native, ffi, bitset, portable) — no base class
- `Symbol` is the currency between the two halves: a rectangular two-level
  bitmap plus quiet zone, optional per-row heights, optional human-readable
  text and symbology metadata
- Option bags are readonly; render options extend `AbstractRenderOptions`,
  generator options are per-symbology (`QrOptions`, `DataMatrixOptions`)
- Enums for `Symbology`, `Format`, `ErrorCorrectionLevel`, `ModuleStyle`,
  `ModuleShape`, `Dimension`, `Mode` — but every API takes `string|Enum`, because
  the registry is open and a caller's own generator must be a first-class citizen

## Project Structure

```
src/
  Generator/          # One directory per symbology, each with Backend/
    Ean/              # Tables shared by the whole EAN/UPC family
  Renderer/           # Output format implementations
    Options/          # Render option bags
  Encoding/           # QR encoding logic
  Options/            # The two option interfaces
  Exception/          # Custom exceptions
  *.php               # Scanme, Registry, Defaults, Symbol, enums
tests/
  fixtures/           # Reference module strings from independent encoders
  Support/            # Decoder bridge and the ScansBack trait
  *Test.php           # PHPUnit tests
examples/             # Seven runnable examples, executed by tests/ExamplesTest.php
bench/                # Encoder and renderer benchmarks
clib/                 # C++ QR core (FFI + extension)
php-ext/              # PHP extension wrapping clib
tools/                # decode.py and the reference-fixture generators
```

### Verification Discipline

An encoder checked only against tables transcribed from the standard it
implements cannot catch a table that is wrong in the same direction as its
test — and a barcode that is wrong but scannable fails at the till, not in the
suite. So every symbology gets two independent checks:

- **A reference fixture** generated by an encoder we did not write (zxing-cpp),
  compared module for module — `tests/fixtures/*_reference.csv`, regenerated by
  `composer reference:ean-upc` and friends
- **A decoder round trip** — render a real PNG, hand it to zxing-cpp, require
  the payload and the symbology back (`tests/DecoderRoundTripTest.php`)

When adding a symbology, both are part of the work, not a follow-up.

## CI/CD

GitHub Actions runs on PHP 8.2, 8.3, 8.4.
Requires write permissions to run CI.

## GitHub Workflow

### Task/Issue Management
- List open issues: `gh issue list`
- View specific issue: `gh issue view <number>`
- List open PRs: `gh pr list`
- View specific PR: `gh pr view <number>`
- Create new issue: `gh issue create --title "..." --body "..."`
- Close issue: `gh issue close <number>`

### Branches & PRs
- **NEVER push directly to `main`** - always create a Pull Request for review
- Always work on a feature branch: `git checkout -b feature/<name>`
- Push branch and create PR: `gh pr create`
- Wait for CI to pass: `gh pr checks <number> --watch`
- **Merge only after developer approval** - never merge your own PR without review
- Merge PR: `gh pr merge <number> --merge --delete-branch`

### Releasing a Version
**ALWAYS update CHANGELOG.md before committing a release.**

1. Update `CHANGELOG.md`:
   - Move items from `## [Unreleased]` to a new `## [X.Y.Z] - YYYY-MM-DD` section
   - Add new `[Unreleased]` link and versioned link at the bottom
2. Commit: `git commit -m "docs: update CHANGELOG for vX.Y.Z release"`
3. Push to main
4. Create GitHub release: `gh release create vX.Y.Z --title "vX.Y.Z" --notes "..."`

### CHANGELOG Rules
- Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
- Sections: `Added`, `Changed`, `Fixed`, `Removed`
- Every PR that adds features or fixes bugs must have a CHANGELOG entry under `## [Unreleased]`
- On release: move `[Unreleased]` entries to the new version section
- Never leave released changes under `[Unreleased]`
- `version` field must NOT be present in `composer.json` (Packagist uses git tags)
