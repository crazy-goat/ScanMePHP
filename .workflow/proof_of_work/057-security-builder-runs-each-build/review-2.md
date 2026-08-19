# Review Round 2 — issue #57 (security: Builder runs each command twice + leaks stderr)

**Branch:** `fix/issue-57-security-builder-runs-each-build`
**Commit:** 1c38efe (fix commit on top of cbd5f13)
**Reviewer:** review-critical (round 2)
**Date:** 2026-08-19

## Overall verdict: APPROVE

Round 2 cleanly addresses all four round-1 findings. No new issues were
introduced by the fix. All local gates pass. The security invariant
(no stderr/path leak via exception messages) is now guarded by a dataProvider
test covering all four `BuildException` factories.

## Per-finding status (round 1 → round 2)

### F-1 — Missing CHANGELOG entry | medium → **fixed (verified)**

**Evidence:** `CHANGELOG.md` lines 33–38 (re-read on current branch):
```
### Fixed

- `Builder::build()` now runs each build command (cmake, make) exactly once
  instead of twice (it previously ran `shell_exec()` for output and `exec()`
  again for the exit code). Stderr is no longer merged into captured output
  (`2>&1` removed), so local paths and environment details are not leaked via
  exception messages; build failures now throw a sanitised `BuildException`
  with the exit code only (#57)
```
- Located under `## [Unreleased]` → `### Fixed` — correct section per
  AGENTS.md ("every PR that fixes bugs must have a CHANGELOG entry under
  `## [Unreleased]`") and Keep a Changelog.
- References `(#57)`.
- Describes both halves of the fix: single execution + no `2>&1` + sanitised
  `BuildException`.
- Project convention uses `### Fixed` for security fixes (see the #48
  checksum-mandatory entry also under `### Fixed`), so `### Fixed` is the
  correct section even though the issue has a security dimension.

**Status: fixed, round 2 (verified).**

### F-2 — Security-invariant test only covers 1 of 4 factories | low → **fixed (verified)**

**Evidence:** `tests/BuilderTest.php` lines 96–133 (re-read on current branch):
- New method `testBuildExceptionFactoriesAreSanitised` with
  `@dataProvider sanitisedFactoryProvider`.
- Provider returns all four factories:
  - `['toolsNotAvailable', [], 'Build tools not available']`
  - `['cmakeFailed', [127], 'exit code 127']`
  - `['buildFailed', [2], 'exit code 2']`
  - `['libraryNotFound', ['/Users/secret/project/clib/build'], 'build directory']`
- Assertions per case: `instanceof BuildException`, `instanceof \RuntimeException`,
  `assertStringContainsString($expectedFragment)`, `assertStringNotContainsString('2>&1')`,
  `assertStringNotContainsString('/Users/')`, `assertStringNotContainsString('C:\\')`,
  `assertDoesNotMatchRegularExpression('#^/[A-Za-z]|\\\\[A-Z]:#')`.

**Empirical re-verification (PHP CLI, current branch):**
- `libraryNotFound('/Users/secret/project/clib/build')` → message:
  `Built library not found in build directory: build` — no `/Users/`, no `2>&1`,
  no `C:\`, regex does not match. All assertions pass.
- Simulated regression (removed `basename()`): message would contain
  `/Users/secret/project/clib/build` → `assertStringNotContainsString('/Users/')`
  catches it. The test is a meaningful guard, not a tautology.
- `vendor/bin/phpunit --filter testBuildExceptionFactoriesAreSanitised`:
  4 / 4 (100%), 28 assertions. All pass.

**Status: fixed, round 2 (verified).**

### F-3 — Tautological `basename()` | nit → **not a real finding (verified)**

**Evidence:** `src/Exception/BuildException.php:26`:
```php
return new self(sprintf('Built library not found in build directory: %s', basename($buildPath)));
```
- `basename()` is the security control that strips the absolute path.
  `Builder::build()` always constructs `$buildPath` with forward slashes
  (`$this->projectRoot . '/clib' . '/build'`, lines 34, 44), so `basename()`
  always returns `'build'` on every platform — no leak.
- Empirically verified: `basename('/Users/secret/project/clib/build')` →
  `'build'`. Message: `Built library not found in build directory: build`.
  No `/Users/` present.
- The non-fix rationale in `code-decision-2.md` (citing DEC-002: smallest
  correct change) is sound: removing the directory name would reduce
  diagnostic value with no security gain; using the full path would re-open
  the leak that F-2 now guards.
- **Edge case checked and dismissed:** `basename()` on a Windows backslash
  path (`C:\Users\secret\clib\build`) on Unix returns the full string (no
  stripping), which would be a leak. However, this is unreachable: the sole
  caller (`Builder::build()` line 80) always uses forward slashes, and no
  external caller can inject a backslash path into `libraryNotFound()`.
  Not a real vulnerability.

**Status: not a real finding; deliberately not fixed (verified sound).**

### F-4 — Dead `$cmakeOutput` / `$makeOutput` + `@param-out` hack | nit → **fixed (verified)**

**Evidence:** `src/Builder.php` (re-read on current branch):
- New signature: `private function runCommand(string $command): int` (line 97).
  Returns only the exit code. No `&$output` parameter. No `@param-out` PHPDoc.
- Callers updated:
  - Line 58: `$cmakeExitCode = $this->runCommand($cmakeCmd);`
  - Line 70: `$makeExitCode = $this->runCommand($makeCmd);`
- `grep -rn '&$output\|param-out\|cmakeOutput\|makeOutput' src/ tests/` →
  no hits in Builder/BuildException (only unrelated `$output` in
  `tests/Composer/PluginTest.php:44`, a test mock callback).
- `$lines` local in `runCommand()` (line 100) is a required receptacle for
  `exec()`'s by-ref array parameter; it is not dead code — PHPStan confirms
  no error. Using `exec()` (not `shell_exec()`) is correct because we need
  the exit code.
- PHPStan: `vendor/bin/phpstan analyse src/Builder.php src/Exception/BuildException.php tests/BuilderTest.php` → `[OK] No errors`.
- No regression: the captured stdout was never read by any caller (the dead
  variables were the point of F-4); removing the by-ref param changes only
  the unused seam. The "future structured-logging seam" is not lost — adding
  a return or logger to one private method is trivial.

**Status: fixed, round 2 (verified).**

## New findings introduced by round 2

**None.**

The round-2 diff touches only:
- `CHANGELOG.md` (6 lines added under `### Fixed`)
- `src/Builder.php` (20 lines changed: removed by-ref param, `@param-out`,
  dead locals; updated comments)
- `tests/BuilderTest.php` (37 lines added: new dataProvider test)
- `.workflow/proof_of_work/` files (review/decision artifacts)

No production code path changed its behaviour. The `runCommand()` signature
change is internal (private method). No new security surface, no API
breakage, no new dependencies.

## High-risk areas re-checked in round 2

| Area | Result |
|------|--------|
| `runCommand()` signature change | **Clean.** Private method, only two callers, both updated. PHPStan clean. |
| By-ref param removal | **Clean.** No leftover `&$output` usage anywhere. `exec()` still gets its required array param via local `$lines`. |
| DataProvider test meaningfulness | **Clean.** All 4 factories tested; regression simulation confirms the test catches a `basename()` removal. |
| CHANGELOG format | **Clean.** Under `## [Unreleased]` → `### Fixed`, references #57, follows Keep a Changelog. |
| Backward compatibility | **Clean.** `BuildException extends \RuntimeException`; sole caller `InstallScript.php` catches `\Exception` and echoes `getMessage()` (no string-equality branching). |
| `basename()` platform edge case | **Checked, not exploitable.** Backslash paths would leak on Unix, but `Builder::build()` always uses forward slashes; no external caller can inject a backslash path. |
| Zero runtime deps (FAQ-004) | **Clean.** No `composer.json` `require` change. |
| Command injection (DEC-006 adjacent) | **Clean.** `escapeshellarg($buildPath)` unchanged; `$buildPath` is internally derived. |
| Commit message convention (DEC-002) | **Clean.** `fix(ffi): address review round 1 findings (#57)` follows `<type>(<scope>): <subject>`. |
| PHPStan level 4 (DEC-005) | **Clean.** No errors, no new baseline entries. |

## Out-of-scope pre-existing issues (unchanged from round 1, not new findings)

- `which` on Windows (coder Finding 1)
- `mkdir()` return value unchecked (coder Finding 2)
- `fgetcsv()` PHP 8.5 deprecation (coder Finding 4)
- Bare `RuntimeException` in `InstallScript::getPackageVersion()` (coder Finding 5)

## Candidate KB entries

No new candidates beyond those proposed in round 1:
1. "Build commands must not merge stderr into captured output" (tags: security, ffi, exception)
2. "BuildException factories are the only sanctioned throw from Builder" (tags: exception, ffi)

## Commands run (read-only)

| Command | Result |
|---------|--------|
| `vendor/bin/phpstan analyse src/Builder.php src/Exception/BuildException.php tests/BuilderTest.php` | **Passed** — `[OK] No errors` |
| `vendor/bin/phpunit --filter Builder` | **Passed** — 8 tests, 34 assertions, OK (1 PHPUnit deprecation: unrelated `fgetcsv`) |
| `vendor/bin/phpunit --filter testBuildExceptionFactoriesAreSanitised` | **Passed** — 4 / 4 (100%), 28 assertions |
| `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.php --allow-risky=yes` | **Passed** — 0 of 55 files need fixing |
| PHP CLI empirical: all 4 `BuildException` factories | **Passed** — no `/Users/`, no `2>&1`, no `C:\`, all `instanceof \RuntimeException` |
| PHP CLI: `basename()` Windows edge case | **Checked** — leak on Unix with backslash paths, but unreachable via actual code |
