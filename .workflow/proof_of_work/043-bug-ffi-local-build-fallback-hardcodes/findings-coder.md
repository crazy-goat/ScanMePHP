# Findings — coder

Issue: #43 — FFI local-build fallback hardcodes `libscanme_qr.so`, never resolves on macOS.
Branch: `fix/issue-43-bug-ffi-local-build-fallback-hardcodes`

## Biggest problem faced

No real obstacle. The issue was well-diagnosed (root cause, file:line, suggested
fix, and verification note all included). The only nuance was scope: the `.so`
literal was not only in `src/FfiEncoder.php` but also duplicated in
`tests/FfiEncoderTest.php:18` and `tests/QrReferenceTest.php:82`, so fixing only
the source would have left the FFI test suites skipping on macOS even with a
built dylib. Fixed all three to actually deliver the issue's outcome ("FFI
fallback resolves on macOS", "the ~1783 skipped QrReferenceTest cases run").

## Discovered bugs / places to improve

Each with file:line and a suggested fix. None are in scope for #43; recorded
here for step 14.

### 1. `fgetcsv()` without `$escape` triggers PHP 8.4+ deprecation — TRACKED as #40
- `tests/QrReferenceTest.php:46-47`
- Already tracked: issue #40 (`chore: fgetcsv() calls without $escape parameter
  trigger PHP deprecation in QrReferenceTest`). Confirm overlap before filing.
- Suggested fix: pass the explicit default `','` (or `''`/`"\\\\"` per intent)
  to `fgetcsv()` so the deprecation disappears and the 8 deprecation notices in
  the test run go away.

### 2. C++ build suffix not pinned by CMake — possible cross-platform papercut
- `clib/CMakeLists.txt` — uses `add_library(scanme_qr SHARED ...)` with the
  platform-default suffix and no `SUFFIX`/`PREFIX` override.
- Not a bug: produces `.so`/`.dylib`/`.dll` per platform, which the code now
  expects. But there is no test asserting the built artifact's suffix matches
  what `FfiEncoder::resolveLibraryPath()` looks for. If a future CMake change
  (e.g. a `CMAKE_SHARED_LIBRARY_SUFFIX` override, or a static build) landed, the
  runtime would silently fall through to the pure-PHP encoder again with no
  signal.
- Suggested fix (improvement, low severity): add a small build-smoke test that,
  when `clib/build/` exists, asserts `FfiEncoder::resolveLibraryPath()` is
  non-null on the current platform — turning a silent fallback into a failure.
  This would also have caught #43 itself.

### 3. Vendor binary vs local-build naming asymmetry (debugging papercut)
- `src/Composer/Plugin.php:340` / `src/PlatformDetector.php:55` name vendor
  binaries `libscanme_qr-macos-<arch>.dylib` (arch-suffixed), while local builds
  are unsuffixed `libscanme_qr.dylib`.
- Already called out in issue #43's body as "no runtime impact … debugging
  papercut". Not filing.
