# Review — Round 2 (issue #39, branch `fix/issue-39-bug-qrcode-never-uses-nativeencoder`)

Scope: diff `7231a55..HEAD` on `src/` and `tests/` (round-1 fixes applied in `a080736`).
Files inspected: `src/FfiEncoder.php`, `src/QRCode.php`, `src/NativeEncoder.php`, `tests/ExtensionNameConsistencyTest.php`, `src/PlatformDetector.php`.

## Verdicts on prior findings

### R1-1 — Duplicated path resolution logic → **fixed**
Evidence: `src/FfiEncoder.php:80-100` defines `public static function resolveLibraryPath(): ?string` as the single source of truth — vendor binary first (`dirname(__DIR__) . '/../../crazy-goat/scanmephp/ffi-binaries/' . PlatformDetector::getCurrentPlatformBinaryName()`), then local build (`dirname(__DIR__) . '/clib/build/libscanme_qr.so'`), else `null`. `src/QRCode.php:39-42` calls `FfiEncoder::resolveLibraryPath()` and only constructs `FfiEncoder` when non-null. `src/NativeEncoder.php:38-44` uses `FfiEncoder::resolveLibraryPath() ?? throw new \RuntimeException(...)`. Neither `src/QRCode.php` nor `src/NativeEncoder.php` contains the literal `'/clib/build/libscanme_qr.so'` (grep confirms the only occurrence is in `src/FfiEncoder.php:92`). Path resolution order is byte-identical to the old per-call-site code (same `dirname(__DIR__)` base, since both files live in `src/`).

### R1-2 — Misleading exception message → **fixed**
Evidence: `src/NativeEncoder.php:39-43` now reads `"... build the FFI library ..., enable the ext-ffi extension, or install the scanmeqr PHP extension."`. The `ext-ffi` case is now covered. Message starts with "No native ScanMePHP library available:" (was "found:") — accurate, since the resolver returns null when ext-ffi is absent or no binary exists.

### R1-3 — Missing comment on future-guard test → **fixed**
Evidence: `tests/ExtensionNameConsistencyTest.php:83-87` has a `// NOTE:` block above `testDefaultEncoderIsNotNativeEncoderWhenExtensionIsMissing` stating it is a future-guard, not the #39 regression test, and pointing to `testAllEncoderSelectionPathsUseTheSameExtensionName` as the real guard.

## New findings (round 2)

None.

## Areas checked clean

- **`resolveLibraryPath()` return type & correctness**: `?string` is correct — returns a path only when `self::isAvailable()` (which requires `extension_loaded('ffi') && file_exists(...)`) is true, else `null`. No behavior change vs. old per-call-site checks.
- **`QRCode::createDefaultEncoder` flow preserved**: old flow constructed `new FfiEncoder($path)` only after `isAvailable` was true; new flow does the same (resolver returns non-null iff `isAvailable` was true, then QRCode constructs `FfiEncoder`). If `FFI::cdef` throws inside the constructor, the exception propagates identically. Fall-through to `FastEncoder`/`Encoder` when both paths fail is preserved (resolver returns null → QRCode proceeds to `PHP_INT_SIZE >= 8` branch). No exception-type or selection-order regression.
- **`NativeEncoder` fallback `?? throw`**: valid PHP 8.2+ (throw is an expression). Exception is reachable: reachable whenever `resolveLibraryPath()` returns null (ext-ffi absent or no binary). Message accurate.
- **Source-string contract test robustness**: `testFfiLibraryPathResolutionIsCentralized` asserts `assertStringNotContainsString("'/clib/build/libscanme_qr.so'", ...)` against `src/QRCode.php` and `src/NativeEncoder.php` only — neither file contains that literal in code or comments (grep confirms sole occurrence is in `src/FfiEncoder.php`, which is intentionally not in the `callSites` list). No false-failure risk.
- **`testResolveLibraryPathReturnsUsableOrNull`**: sound invariant — result is null OR `isAvailable($path)` is true, which is exactly the postcondition of the resolver.
- **PSR-12 / strict types / return types**: `FfiEncoder.php` has `declare(strict_types=1)`, `resolveLibraryPath()` is `public static`, returns `?string`, no parameters. Conforms to AGENTS.md conventions.
- **Tests**: `ExtensionNameConsistencyTest` runs green (4 tests, 13 assertions). Per task brief, full suite is green: `Tests: 5391, Assertions: 7290, Deprecations: 8, Skipped: 1783` — no new failures/deprecations introduced by round 1.
- **Zero-runtime-deps**: `composer.json` `require` untouched by this diff.

## Overall verdict: **APPROVE**

Round-1 fixes for R1-1, R1-2, R1-3 are all correctly present and complete. Centralization is behavior-preserving, the new tests are robust, and no new issues were introduced. The change is safe to merge.

## Candidate KB entries

- (optional) KB-FFI-PATH: "FFI library path resolution must go through `FfiEncoder::resolveLibraryPath()`; do not re-duplicate the vendor/local-build candidate literals in `QRCode.php` or `NativeEncoder.php`." Enforced by `testFfiLibraryPathResolutionIsCentralized` — recommend adding this check to the PR's regression set (already added).
