# ScanMePHP - Agent Guidelines

Pure PHP QR code generator with zero dependencies. PHP 8.1+.

## Build & Test Commands

```bash
# Run all tests
composer test
# OR
vendor/bin/phpunit

# Run a single test method
vendor/bin/phpunit --filter testBasicAsciiQrCode

# Run tests from a specific file
vendor/bin/phpunit tests/QRCodeTest.php

# Run with coverage (if xdebug installed)
vendor/bin/phpunit --coverage-text

# Validate composer files
composer validate --strict

# Install dependencies
composer install
```

## Code Style Guidelines

### PHP Version & Strict Types
- **PHP 8.1+ required** - use modern features
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
examples/             # Usage examples
```

## CI/CD

GitHub Actions runs on PHP 8.1, 8.2, 8.3, 8.4.
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
- Always work on a feature branch: `git checkout -b feature/<name>`
- Push branch and create PR: `gh pr create`
- Wait for CI to pass: `gh pr checks <number> --watch`
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
