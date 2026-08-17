# Coder findings — 039-bug-qrcode-never-uses-nativeencoder

Appended across the cycle. Obstacles, surprises, and bugs/weak spots noticed
in passing — including ones outside this change's scope.

## Obstacles / surprises

- The bug was invisible in the test suite because *nothing* pinned the
  `extension_loaded('scanme_qr')` string. The existing suite passes whether
  the extension name is right or wrong (no CI machine has `scanmeqr`
  compiled), so the regression test had to be a string-contract test that
  reads the source files — the only kind of test that actually fails on the
  pre-fix code (verified: it failed before the fix, passes after).
- `src/NativeEncoder.php` is a conditional `if/else` class declaration; both
  branches define a class named `NativeEncoder`. This means
  `class_exists(CrazyGoat\ScanMePHP\NativeEncoder)` is **true even without
  the extension** (the else branch defines it). The `extension_loaded`
  gate in `QRCode::createDefaultEncoder` is therefore the real protection,
  and the `class_exists` guard is effectively redundant — harmless, kept
  as-is.
- The `else` branch of `NativeEncoder` can only be reached by direct
  instantiation (`new NativeEncoder()` without the extension loaded);
  `QRCode` never constructs it without the extension. So the broken
  `new FfiEncoder()` was dead code — which is why it never broke any test.
- PHP 8.5 deprecates `ReflectionMethod::setAccessible()`; since PHP 8.1
  private reflection invocation works without it, the new test avoids the
  call entirely.

## Discovered bugs / weak spots (in passing, outside scope)

- `src/QRCode.php:40` — local-build path hardcodes the Linux suffix:
  `dirname(__DIR__) . '/clib/build/libscanme_qr.so'`. On macOS the CMake
  artifact is `libscanme_qr.dylib`
  (`clib/CMakeLists.txt:40` `add_library(scanme_qr SHARED ...)` → default
  platform suffix), so the FFI local-build fallback never works on macOS
  even when `clib/` is built. Suggest: pick the suffix by platform
  (`PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'`), e.g.
  `$localBuild = dirname(__DIR__) . '/clib/build/libscanme_qr.' . (PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so');`
- `src/NativeEncoder.php:44` — the keep-and-fix fallback copies the same
  hardcoded `libscanme_qr.so` local-build path as `QRCode.php:40` (deliberate:
  same resolution order, minimal diff). The `.so`/`.dylib` issue applies to
  both — fix them together, ideally by extracting a shared
  `resolveLibraryPath()` helper used by both `QRCode::createDefaultEncoder`
  and the `NativeEncoder` fallback.
- `tests/QrReferenceTest.php:46-47` — `fgetcsv($handle)`/`fgetcsv($handle)`
  without the `$escape` parameter trigger PHP 8.4+ deprecations (8 in the
  current suite). Pre-existing issue #40, deliberately NOT fixed here.
  Suggest: add `escape: ''` (PHP 8.4 default) or set `$escape` explicitly.
- `src/QRCode.php:33-34` — vendor-binary path assumes a vendor install
  layout (`src/../../crazy-goat/scanmephp/ffi-binaries/`). In a plain repo
  checkout it resolves outside the repository (harmless today because
  `FfiEncoder::isAvailable()` checks `file_exists`), so only an installed
  package can use it. Same assumption in `NativeEncoder.php:42`.
- Consistency test covers only the three PHP files; the source of truth
  `php-ext/scanme_qr.c:33` (`"scanmeqr"` in `zend_module_entry`) is not
  asserted. A future rename of the C module would not fail the suite.
  Suggest: extend the test to `php-ext/scanme_qr.c` (assert the
  `"scanmeqr"` literal) when the test is next touched.
- `src/Composer/Plugin.php:340` — `getFfiBinaryName()` returns
  `libscanme_qr-macos-%s.dylib` on macOS (`%s` = arch, e.g. `arm64`), but
  the local CMake artifact has no platform/arch suffix — so a downloaded
  vendor binary and a local build of the same library have different
  filenames. Not a bug per se, but worth knowing when debugging "library
  not found" reports.
