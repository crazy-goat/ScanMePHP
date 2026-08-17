# Review Round 1 — Issue #39: NativeEncoder never used (wrong extension name)

**Branch:** `fix/issue-39-bug-qrcode-never-uses-nativeencoder`
**Commit:** `7231a55`
**Reviewer:** review-agent (round 1)
**Date:** 2026-04-01

## Summary

The PR fixes two related issues:
1. **#39 bug fix** — `QRCode::createDefaultEncoder()` checked `extension_loaded('scanme_qr')` (with underscore) but the actual extension name registered in `php-ext/scanme_qr.c:33` is `"scanmeqr"` (no underscore). This meant the native C extension was never selected. The fix corrects the name to `scanmeqr`.
2. **NativeEncoder fallback fix** — When the extension is NOT loaded, the `else`-branch `NativeEncoder` (FFI-based) previously called `new FfiEncoder()->encode(...)` with no constructor argument, causing an `ArgumentCountError` because `FfiEncoder::__construct(string $libraryPath)` requires a path. The fix adds `resolveLibraryPath()` that mirrors `QRCode::createDefaultEncoder`'s path resolution and throws a clear `RuntimeException` if nothing is usable.

A new test file `tests/ExtensionNameConsistencyTest.php` pins all three `extension_loaded('scanmeqr')` call sites and guards the default encoder selection.

## What I Checked

| Area | Method | Result |
|------|--------|--------|
| #39 fix correctness | Compared `extension_loaded` calls in all src/ files against `php-ext/scanme_qr.c` name | ✅ All three call sites (`QRCode.php:29`, `NativeEncoder.php:10`, `Composer/Plugin.php:125`) now use `scanmeqr`. No other `scanme_qr` references exist. |
| Consistency test | Read `ExtensionNameConsistencyTest.php`, traced assertions | ✅ Pins all 3 files. Would have failed pre-fix (QRCode.php had `scanme_qr`). |
| Reflection test | Read test, checked PHP 8.2+ reflection semantics | ✅ `ReflectionMethod::invoke()` on private method works without `setAccessible()` in 8.1+. Properly guarded with `markTestSkipped`. Does NOT specifically exercise #39 (passes both pre/post fix) — see findings. |
| NativeEncoder fallback | Read `NativeEncoder.php`, `FfiEncoder.php`, `QRCode.php` | ✅ `resolveLibraryPath()` mirrors `createDefaultEncoder` exactly. `RuntimeException` propagates cleanly. `else`-branch class is still `final`. |
| Path duplication | Compared `resolveLibraryPath()` with `createDefaultEncoder()` | ⚠️ Logic is duplicated — see findings (low). |
| Zero-runtime-deps | Inspected `composer.json` require | ✅ No new deps. `require` still only `php` + `composer-plugin-api`. FAQ-004 satisfied. |
| PHPStan | Ran `vendor/bin/phpstan analyse` | ✅ No errors. `NativeEncoder.php` excluded from analysis (DEC-005, FAQ-005) — no new baseline entry needed. |
| Lint (php-cs-fixer + phpstan + rector + kb-lint) | Ran `composer lint` | ✅ All clean, exit 0. |
| Full test suite | Ran `vendor/bin/phpunit` | ✅ Tests: 5389 (+2), Assertions: 7285, Deprecations: 8 (pre-existing, issue #40), Skipped: 1783. No regressions. |
| Commit message | `git log` | ✅ `fix(core): ... (closes #39)` — matches DEC-002. |

## Findings

| # | File:Line | Description | Severity | Status |
|---|-----------|-------------|----------|--------|
| 1 | `src/NativeEncoder.php:41-52` | **Duplicated path resolution logic** — `resolveLibraryPath()` copies the vendor-binary + local-build resolution from `QRCode::createDefaultEncoder()` verbatim. If the vendor path or local build path changes, both must be updated in sync. The consistency test only pins the extension name, not the path. Consider extracting to a shared static helper or at minimum adding a test that asserts the paths match. | low | open |
| 2 | `src/NativeEncoder.php:51` | **RuntimeException message incomplete for ext-ffi case** — If `ext-ffi` is not loaded, `FfiEncoder::isAvailable()` returns false for all paths, and the RuntimeException says "build the FFI library or install the scanmeqr PHP extension." It doesn't mention enabling `ext-ffi`. Only affects users who explicitly instantiate `NativeEncoder` without the extension (the default path falls back to `FastEncoder`/`Encoder`). | nit | open |
| 3 | `tests/ExtensionNameConsistencyTest.php:40-52` | **Reflection test doesn't exercise the #39 bug** — `testDefaultEncoderIsNotNativeEncoderWhenExtensionIsMissing` asserts NativeEncoder is NOT returned when the extension is absent. This was already true pre-fix (the wrong name `scanme_qr` also evaluated to false). The test is a useful future guard but does not serve as a regression test for #39. The consistency test (`testAllEncoderSelectionPathsUseTheSameExtensionName`) is the actual regression test. Consider a comment clarifying this distinction. | low | open |

## Verdict

**APPROVE**

The core fix is correct, all three `scanmeqr` call sites are consistent, the fallback `ArgumentCountError` is properly resolved, no regressions, lint/PHPStan/tests all pass, zero-deps principle preserved, and the commit message follows DEC-002. The three findings are all low/nit — the path duplication is the most actionable (recommend a follow-up to extract shared path resolution), and the consistency test effectively prevents regression of #39.

## Candidate Knowledge-Base Entries (propose only, not written)

1. **"Extension name single-source-of-truth test"** — tags: `ffi`, `phpunit`, `zero-deps`, trigger: adding a new `extension_loaded('scanmeqr')` call site. One paragraph: When adding a new code path that gates on `extension_loaded('scanmeqr')`, add the file to the `$files` array in `ExtensionNameConsistencyTest`. The test asserts all call sites use the exact string `extension_loaded('scanmeqr')` and none contains the misspelled `extension_loaded('scanme_qr')`. This catches the class of bug from #39 where the extension name was misspelled in one file but correct in others.

## Automated checks that could catch findings

- Finding #1 (path duplication): A test asserting `NativeEncoder::resolveLibraryPath()` produces the same candidate paths as `QRCode::createDefaultEncoder()` — not currently covered. Recommend adding in this PR or a follow-up.
- Finding #3 (test intent): No automated check; a code comment suffices.
