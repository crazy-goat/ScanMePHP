# Code decision — round 1

Issue: #43 — FFI local-build fallback hardcodes `libscanme_qr.so`, never resolves on macOS.
Branch: `fix/issue-43-bug-ffi-local-build-fallback-hardcodes`

## Approach taken

`FfiEncoder::resolveLibraryPath()` (the single source of truth for FFI library
path resolution) hardcoded the local-build path with the Linux shared-library
suffix `.so`. On macOS, CMake produces `libscanme_qr.dylib`, so
`isAvailable($localBuild)` was always false and the FFI fallback never resolved
— silently falling through to the pure-PHP encoder and skipping ~1783
`QrReferenceTest` cases plus all `FfiEncoderTest` cases on macOS.

Fix: pick the suffix by platform, exactly as the issue suggested:

```php
$suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
$localBuild = dirname(__DIR__) . '/clib/build/libscanme_qr.' . $suffix;
```

The same `.so` literal was duplicated in two test entry points
(`tests/FfiEncoderTest.php`, `tests/QrReferenceTest.php`), which independently
caused the same skip-on-macOS even though the dylib was built. Applied the same
platform-aware suffix in both so the suites actually exercise FFI on macOS.
Also made the skip messages platform-neutral.

`src/Builder.php` was inspected but left untouched: `findBuiltLibrary()` already
checks `.so`, `.dylib`, and `.dll`, so it is platform-correct.

`tests/ExtensionNameConsistencyTest::testFfiLibraryPathResolutionIsCentralized()`
pins that callers (`QRCode`, `NativeEncoder`) must not contain the
`'/clib/build/libscanme_qr.so'` literal. This change removes that literal from
`FfiEncoder.php`, so the contract still holds (and is now more robust — the
literal is gone entirely rather than just absent from the two call sites).

## What was rejected

- **Routing the tests through `FfiEncoder::resolveLibraryPath()` instead of
  building the path inline.** The tests deliberately construct `FfiEncoder`
  with an explicit path (to test the constructor / encode path in isolation,
  independent of vendor-binary availability). Using `resolveLibraryPath()` would
  conflate "is the library present" with "which library does the resolver pick",
  and could make the tests silently exercise a vendor binary instead of the
  local build. Keeping the explicit, platform-aware path preserves intent.

- **A shared helper for the suffix.** Three call sites with a one-line ternary
  is below the threshold for a new abstraction; a helper would add an import and
  a indirection for no clarity gain. Reconsider if a fourth site appears.

## What was uncertain

- Whether `testLibraryNotFoundThrows()` (line 107, `/nonexistent/libscanme_qr.so`)
  should be made platform-aware. Decided **no**: the path is deliberately
  nonexistent and the suffix is irrelevant to the assertion (`RuntimeException`
  matching `/not found/`). Left as-is to keep the diff minimal and the test's
  intent obvious.

## Verification

- `clib/build/libscanme_qr.dylib` exists locally; ext-ffi loaded; `PHP_OS_FAMILY === 'Darwin'`.
- Before fix: `resolveLibraryPath()` returned `null`; FFI tests skipped.
- After fix:
  - `tests/FfiEncoderTest.php` → 10 tests, 665 assertions, OK (previously skipped).
  - `tests/QrReferenceTest.php` → 5316 tests, 10632 assertions, OK (previously skipped).
  - `tests/ExtensionNameConsistencyTest.php` → 4 tests, OK (contract still holds).
  - Full suite: 5391 tests, 11499 assertions, 0 failures, 8 deprecations (all
    pre-existing issue #40 `fgetcsv` `$escape` deprecations, out of scope), 1 skipped (unrelated).
- `composer lint`: php-cs-fixer, phpstan, rector, kb-lint all clean.
