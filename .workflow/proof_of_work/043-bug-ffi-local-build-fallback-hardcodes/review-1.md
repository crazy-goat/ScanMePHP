# Review Round 1 — Issue #43: FFI local-build fallback hardcodes `.so`

**Branch:** `fix/issue-43-bug-ffi-local-build-fallback-hardcodes`
**Date:** 2025-08-17
**Reviewer:** automated review agent

## Verdict

**Clean with two minor observations.** The fix is correct, complete, and properly
scoped. The FFI tests actually exercise the native encoder on macOS instead of
silently skipping. No regressions, no new runtime dependencies, no contract
breakage.

## What was checked

### 1. `PHP_OS_FAMILY` is the right constant
- `PHP_OS_FAMILY` is available since PHP 7.2; project requires 8.2+. ✓
- Returns `'Darwin'` on macOS, `'Linux'` on Linux, `'Windows'` on Windows. ✓
- Already used consistently in `src/PlatformDetector.php:11`, `src/Composer/Plugin.php:273,280`,
  `src/BinaryDownloader.php:94`. ✓
- The `=== 'Darwin' ? 'dylib' : 'so'` ternary correctly maps macOS→dylib, everything else→so. ✓

### 2. All local-build `.so` literals addressed
Grepped all `clib/build` references in `src/` and `tests/`:
- `src/FfiEncoder.php:93` — now dynamic. ✓
- `tests/FfiEncoderTest.php:19` — now dynamic. ✓
- `tests/QrReferenceTest.php:83` — now dynamic. ✓
- `tests/FfiEncoderTest.php:108` — `/nonexistent/libscanme_qr.so` in exception test;
  the file doesn't exist so the suffix is irrelevant. Not a real finding. ✓
- `tests/ExtensionNameConsistencyTest.php:64` — `assertStringNotContainsString` guard
  checking that the literal does NOT appear in QRCode.php/NativeEncoder.php. Still valid. ✓

### 3. `src/Builder.php` is consistent
`Builder::findBuiltLibrary()` (line 89-100) already tries `libscanme_qr.so`,
`libscanme_qr.dylib`, and `scanme_qr.dll` in sequence. No change needed. ✓

### 4. `ExtensionNameConsistencyTest` source-string contract
- `testFfiLibraryPathResolutionIsCentralized()` checks that `src/QRCode.php` and
  `src/NativeEncoder.php` do NOT contain `'/clib/build/libscanme_qr.so'`.
- The fix changed `src/FfiEncoder.php`, not those two files, so the contract holds.
- Ran the test: **4 tests, 13 assertions — OK.** ✓

### 5. Tests actually exercise FFI on macOS (not just mask the skip)
- `clib/build/libscanme_qr.dylib` exists on this machine.
- `FfiEncoderTest`: **10 tests, 665 assertions — OK** (no skips).
- `QrReferenceTest::testFfiEncoderMatchesReference`: **1772 tests, 3544 assertions — OK** (no skips).
- Before the fix, these would have skipped because `libscanme_qr.so` doesn't exist on macOS. ✓

### 6. Zero-deps principle
- `composer.json` `require` unchanged: only `php: ^8.2` and `composer-plugin-api: ^2.0`.
- No new runtime dependencies introduced. ✓

### 7. Lint
- `composer lint`: PHP CS Fixer (0 fixes needed), PHPStan (OK), Rector (OK), kb-lint (0 warnings). ✓

### 8. CHANGELOG
- Added Fixed entry under `## [Unreleased]` with issue reference (#43).
- Follows Keep a Changelog format. ✓

### 9. No staged files
- `git diff --cached` is empty. ✓

## Findings

### Low — Suffix logic duplicated in 3 locations
**Files:** `src/FfiEncoder.php:92`, `tests/FfiEncoderTest.php:18`, `tests/QrReferenceTest.php:82`

The `PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'` expression is now in three
places. The tests intentionally construct the local-build path directly (rather
than calling `resolveLibraryPath()`, which would also match vendor binaries),
so the duplication is architecturally justified. However, if a new
platform-specific suffix is needed, all three must be updated in lockstep.

**Automated check that could catch divergence:** A source-string contract test
(similar to `ExtensionNameConsistencyTest`) that verifies the suffix expression
in the test files matches the one in `FfiEncoder.php`. Or, extract a
`FfiEncoder::localBuildPath()` static method that the tests call.

**Recommendation:** Acceptable for this PR. Consider extracting a shared method
in a future refactor if the pattern proliferates.

### Nit — Unintended example asset modifications in working tree
**Files:** `examples/generated-assets/qrcode_dark.svg`, `qrcode_fullblocks.txt`,
`qrcode_halfblocks.txt`, `qrcode_simple.txt`

These four files are modified in the working tree but are not part of the fix.
They appear to be regenerated artifacts (trailing blank lines added to `.txt`
files, SVG reformatted). They should be reverted before committing to avoid
PR noise.

**Automated check that could catch this:** `git diff --stat` in a pre-commit
hook, or a CI check that verifies only intended files are changed.

## Proposed `.workflow/helpers/` entries

### FAQ-006 (candidate)
```
<!-- id=FAQ-006, tags=ffi phpunit, trigger=FFI tests silently skip on macOS, status=active -->
The local CMake build produces `libscanme_qr.dylib` on macOS and
`libscanme_qr.so` on Linux. Any code or test that hardcodes `.so` for the
local build path will fail to resolve on macOS and silently fall through to
the pure-PHP encoder (tests skip instead of running). Always derive the suffix
from `PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'`. The vendor binary names
in `PlatformDetector` already handle this correctly; only the local build
path was affected (fixed in #43).
```
