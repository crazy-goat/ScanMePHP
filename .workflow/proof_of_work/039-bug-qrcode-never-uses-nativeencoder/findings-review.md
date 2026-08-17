# Findings — Review (issue #39)

Round 1 findings. Status legend: open / fixed / not-a-finding.

## Finding R1-1
- **file:line:** `src/NativeEncoder.php:41-52`
- **what:** Duplicated path resolution logic. `resolveLibraryPath()` copies the vendor-binary and local-build path resolution from `QRCode::createDefaultEncoder()` verbatim. If the vendor path or local build path changes in one place but not the other, the two will silently diverge. The consistency test only pins the extension name string, not the library paths.
- **severity:** low
- **status:** fixed (round 1) — extracted `FfiEncoder::resolveLibraryPath(): ?string` as the single source of truth (vendor binary first, then local build, or null). `QRCode::createDefaultEncoder()` and `NativeEncoder`'s no-extension fallback both now route through it; the duplicated literals were removed from both. Added `testFfiLibraryPathResolutionIsCentralized` (source-string contract pinning both call sites use `FfiEncoder::resolveLibraryPath()` and neither duplicates the `libscanme_qr.so` literal) and `testResolveLibraryPathReturnsUsableOrNull` to `ExtensionNameConsistencyTest`.
- **automated check:** A test asserting the two code paths produce the same candidate paths (e.g., reflection on both methods or a shared static helper with a unit test). — ADDED in this PR.

## Finding R1-2
- **file:line:** `src/NativeEncoder.php:51`
- **what:** The `RuntimeException` message says "build the FFI library ... or install the scanmeqr PHP extension" but does not mention the case where `ext-ffi` itself is not loaded. When `ext-ffi` is absent, `FfiEncoder::isAvailable()` returns false for all paths, so this exception is thrown with a misleading message. Only affects users who explicitly instantiate `NativeEncoder` without the C extension.
- **severity:** nit
- **status:** fixed (round 1) — message now reads "... build the FFI library ..., enable the ext-ffi extension, or install the scanmeqr PHP extension." (the fallback was also rewritten to use the shared resolver, so the exception is now thrown via `?? throw` on a null resolve result).

## Finding R1-3
- **file:line:** `tests/ExtensionNameConsistencyTest.php:40-52`
- **what:** The reflection test `testDefaultEncoderIsNotNativeEncoderWhenExtensionIsMissing` does not exercise the #39 bug. It asserts NativeEncoder is not returned when the extension is absent — which was true both before and after the fix (the misspelled `scanme_qr` also evaluated to false). The consistency test (`testAllEncoderSelectionPathsUseTheSameExtensionName`) is the actual regression test for #39. A comment clarifying this distinction would help future maintainers.
- **severity:** low
- **status:** fixed (round 1) — added a comment block above the test explaining it is a future-guard, not the #39 regression test, and pointing to `testAllEncoderSelectionPathsUseTheSameExtensionName` as the real guard.

---

## Round 2 verification

- **R1-1 Round 2 verification: fixed.** `src/FfiEncoder.php:80-100` defines `resolveLibraryPath(): ?string` (vendor binary first, then local build, else null); `src/QRCode.php:39-42` and `src/NativeEncoder.php:38-44` both route through it. grep confirms the literal `'/clib/build/libscanme_qr.so'` appears only in `src/FfiEncoder.php:92`, not in either call site. Resolution order byte-identical to the old per-call-site code.
- **R1-2 Round 2 verification: fixed.** `src/NativeEncoder.php:39-43` message now reads "... build the FFI library ..., enable the ext-ffi extension, or install the scanmeqr PHP extension." — covers the ext-ffi-absent case.
- **R1-3 Round 2 verification: fixed.** `tests/ExtensionNameConsistencyTest.php:83-87` has a `// NOTE:` block above `testDefaultEncoderIsNotNativeEncoderWhenExtensionIsMissing` clarifying it is a future-guard, not the #39 regression test.

No new findings (R2-*): none.
