# Review Round 1 — issue #57 (security: Builder runs each command twice + leaks stderr)

**Branch:** `fix/issue-57-security-builder-runs-each-build`
**Commit:** cbd5f13
**Reviewer:** review-critical (round 1)
**Date:** 2026-08-19

## Overall verdict: APPROVE-WITH-NITS

The change correctly fixes both problems reported in issue #57:
1. **Double execution** — the old `shell_exec($cmd)` + `exec($cmd, …)` pair ran
   cmake and make twice each. The new `runCommand()` helper uses a single
   `exec()` that yields both stdout (array param) and exit code. Confirmed by
   diff and by reading `src/Builder.php`.
2. **Stderr info-leak** — `2>&1` was removed from both command strings.
   `exec()` now captures stdout only; stderr goes to the PHP process's stderr
   (the composer-install terminal / CI log), NOT into any captured variable.
   Exception messages are produced by static `BuildException` factories that
   contain only a fixed string + exit code — never raw output. Empirically
   verified: `cmakeFailed(127)` → "CMake configuration failed (exit code 127).
   See logs for details." and `libraryNotFound('/Users/secret/…/clib/build')`
   → "Built library not found in build directory: build" (basename strips the
   absolute path). No leak.

No blockers found. The findings below are nits / low-severity gaps. Two are
process-compliance (CHANGELOG, test-gap for a security invariant) and worth
addressing in this PR; the rest are cosmetic or already self-reported by the
coder.

## High-risk areas checked clean

| Area | Result |
|------|--------|
| Stderr leak into exception messages | **Clean.** `2>&1` removed; factories use exit code only; `basename()` on the only path-bearing factory. Verified empirically. |
| Double execution | **Clean.** Single `exec()` per command via `runCommand()`. |
| Backward compatibility | **Clean.** `BuildException extends \RuntimeException`; sole caller `InstallScript.php` catches `\Exception` and echoes `getMessage()` (no string-equality on build messages). `expectExceptionMessage('Build tools not available')` is a substring match → passes with the longer new message. |
| Namespace / convention | **Clean.** `CrazyGoat\ScanMePHP\Exception` matches siblings (`DownloadException`, `FileWriteException`, …); static factories match project convention. |
| Zero runtime deps | **Clean.** No `composer.json` `require` change (FAQ-004). |
| Command injection / path traversal | **Clean.** `escapeshellarg($buildPath)` on the only interpolated value; `$buildPath` is derived internally from `projectRoot` (composer-install context, not web/attacker-controlled). `make -j$(nproc)` uses fixed `nproc`. |
| `@param-out string` PHPDoc | **Sound.** PHPStan-recommended way to narrow a by-ref nullable out-parameter; `implode("\n", $lines)` always returns `string` (even `''` for empty array). PHPStan level 4 passes. |
| `exec()` output shape vs old `shell_exec()` | **Irrelevant.** `$cmakeOutput`/`$makeOutput` are now dead (never read after `runCommand`); the shape difference (no trailing newline) has no consumer. |

## Findings

### F-1 — Missing CHANGELOG entry  |  severity: medium  |  status: open, round 1
**Where:** `CHANGELOG.md` (no change in this diff).
**Evidence:** `git diff --stat` lists only `src/Builder.php`,
`src/Exception/BuildException.php`, `tests/BuilderTest.php` and two
`.workflow` files. AGENTS.md states: *"Every PR that adds features or fixes
bugs must have a CHANGELOG entry under `## [Unreleased]`"*. This is a security
bug fix. The existing `## [Unreleased]` section has no `### Fixed` entry for
this change.
**Impact:** Violates a documented project rule; the release process
("move Unreleased entries to a versioned section") will silently drop this
fix from the published changelog.
**Smallest safe fix:** Add under `## [Unreleased]` → `### Fixed`:
`- Builder no longer runs cmake/make twice and no longer leaks raw stderr
  (local paths, environment details) into exception messages (#57)`.
**Automated check that could catch this:** a CI/lint guard asserting that a
diff touching `src/` also touches `CHANGELOG.md` under `## [Unreleased]`
(suggest adding to this PR or a follow-up).

### F-2 — Security-invariant test only covers 1 of 4 factories  |  severity: low  |  status: open, round 1
**Where:** `tests/BuilderTest.php` (the two new tests).
**Evidence:** `testBuildExceptionMessageDoesNotLeakOutput` and
`testBuildThrowsBuildExceptionWhenToolsUnavailable` exercise only the
`toolsNotAvailable()` path (tempDir has no `clib/`). The `cmakeFailed()`,
`buildFailed()`, and `libraryNotFound()` factories — the ones that previously
concatenated raw `2>&1` output — have no test asserting their messages are
sanitised. Today they are trivially correct (static strings + exit code /
`basename()`), but a future refactor could re-introduce
`'…: ' . $cmakeOutput` with no test failing.
**Impact:** The security invariant this PR exists to enforce is not guarded
against regression for 3 of 4 code paths.
**Smallest safe fix:** Add a cheap unit test that calls all four
`BuildException` factories directly and asserts each message matches its
expected format and contains no absolute-path pattern / no `2>&1` / no
arbitrary output. (End-to-end cmake-fail testing needs a toolchain and should
stay skippable per FAQ-001/FAQ-003; the factory-level test needs none.)
**Automated check that could catch this:** the unit test itself (PHPUnit).

### F-3 — `libraryNotFound()` message is tautological  |  severity: nit  |  status: open, round 1
**Where:** `src/Exception/BuildException.php:26`.
**Evidence:** `basename($buildPath)` where `$buildPath` is always
`<projectRoot>/clib/build` → `basename()` is always the literal `'build'`.
The message reads "Built library not found in build directory: build".
**Impact:** Cosmetic only — no security leak (verified with a secret absolute
path). The dynamic `%s` adds no information.
**Smallest safe fix:** Drop the parameter and use a fixed message, e.g.
`'Built library not found in the build directory'`; or keep the param but
document that it's intentionally reduced to `basename` for safety.

### F-4 — `$cmakeOutput` / `$makeOutput` are dead variables  |  severity: nit  |  status: open, round 1 (already self-reported by coder, Finding 3)
**Where:** `src/Builder.php:57` (`$cmakeOutput`), `src/Builder.php:69`
(`$makeOutput`).
**Evidence:** Both are written by `runCommand()` but never read afterwards
(exception factories use only the exit code). PHPStan level 4 does not flag
them.
**Impact:** None functionally. Mild readability cost; a future structured
-logging use would need the value, so keeping the seam is defensible.
**Smallest safe fix:** None required. If desired, add a one-line comment
"captured for future structured logging; intentionally not in the message"
next to each call so the deadness is clearly intentional.

## Out-of-scope items already noted by the coder (not new findings)

- `isBuildAvailable()` uses `which`, absent on Windows (coder Finding 1).
- `mkdir()` return value unchecked (coder Finding 2).
- `fgetcsv()` PHP 8.5 deprecation in `QrReferenceTest` (coder Finding 4).
- Bare `RuntimeException` in `InstallScript::getPackageVersion()` (coder
  Finding 5).

These are pre-existing and outside issue #57's scope; listed here only so they
are not re-discovered in a later round.

## Candidate knowledge-base entries

1. **Title:** Build commands must not merge stderr into captured output
   **Tags:** `security`, `ffi`, `exception`
   **Trigger:** writing `exec()`/`shell_exec()` calls in `Builder` or any
   build-step code that forwards output via exception messages.
   **Paragraph:** When running external build commands (cmake, make) via
   `exec()`/`shell_exec()` in `src/Builder.php`, never use `2>&1` to merge
   stderr into the captured output if that output may reach callers via
   exception messages — compiler diagnostics contain absolute local paths and
   environment details. Use a single `exec()` with the array parameter for
   stdout-only capture and surface only a sanitised `BuildException` factory
   message (exit code) to callers; let stderr go to the process's stderr for
   CLI diagnostic output. This was the root cause of #57. `runCommand()` is
   the single seam if structured stderr logging is later needed.

2. **Title:** BuildException factories are the only sanctioned throw from Builder
   **Tags:** `exception`, `ffi`
   **Trigger:** writing `throw` in `src/Builder.php`.
   **Paragraph:** `Builder::build()` must throw `BuildException` via its static
   factories (`toolsNotAvailable`, `cmakeFailed`, `buildFailed`,
   `libraryNotFound`), never a bare `\RuntimeException` with concatenated
   command output. The factories produce sanitised messages containing only an
   exit code or `basename()` — never raw stdout/stderr or absolute paths.
   `BuildException extends \RuntimeException`, so existing
   `catch (\RuntimeException)` / `catch (\Exception)` callers stay compatible.
   If future catch logic must distinguish variants, use exception codes or
   subclasses, not `getMessage()` comparison (see FAQ-007).
