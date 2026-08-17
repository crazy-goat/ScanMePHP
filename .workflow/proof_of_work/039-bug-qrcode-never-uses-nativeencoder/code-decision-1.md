# Code decision 1 — 039-bug-qrcode-never-uses-nativeencoder

The approach taken in round 1, what was rejected, and what was uncertain.

## Approach

Issue #39 has two broken spots. Both were fixed with the smallest changes that
match the issue's intent.

### Fix 1 — `src/QRCode.php:29` (the actual bug)

`extension_loaded('scanme_qr')` → `extension_loaded('scanmeqr')`. The C
extension registers its module as `"scanmeqr"` (`php-ext/scanme_qr.c:33`,
`zend_module_entry` name field), and `src/NativeEncoder.php:10` and
`src/Composer/Plugin.php:125` already used the correct name — only `QRCode`
had the typo. The `class_exists('CrazyGoat\ScanMePHP\NativeEncoder')` guard
was kept unchanged.

### Fix 2 — `src/NativeEncoder.php` else-branch (dead/broken fallback)

**Decision: keep-and-fix** — the else branch now resolves the FFI library
path itself and throws a clear `RuntimeException` when nothing is usable:

1. Vendor binary first: `dirname(__DIR__) . '/../../crazy-goat/scanmephp/ffi-binaries/' . PlatformDetector::getCurrentPlatformBinaryName()` — same expression as `QRCode::createDefaultEncoder`.
2. Then local build: `dirname(__DIR__) . '/clib/build/libscanme_qr.so'` — same expression as `QRCode`.
3. Otherwise throw `RuntimeException` explaining how to obtain a native library, instead of the old confusing `ArgumentCountError` from `new FfiEncoder()` (whose constructor requires `string $libraryPath`).

Rationale for keep-and-fix over removal:

- The file's docblock explicitly advertises a hybrid implementation
  ("Implementacja hybrydowa" — extension first, FFI fallback), so the
  fallback is intentional design, not an accident; only its
  implementation was broken.
- Removal would make `NativeEncoder` conditionally undefined (class exists
  only when the extension is loaded). Any consumer doing `new NativeEncoder()`
  without the extension would then crash with a "Class not found" fatal —
  a worse failure mode than a clear `RuntimeException`, and a public API
  regression for a class that today always exists.
- `QRCode` never reaches this branch (it only constructs `NativeEncoder`
  when the extension is loaded), so the only effect is making a direct
  `new NativeEncoder()` without the extension fail loudly and informatively
  instead of with an `ArgumentCountError`.

The path-resolution duplication with `QRCode::createDefaultEncoder` is
accepted deliberately: extracting a shared resolver would widen scope
beyond #39. It is flagged in findings as a candidate follow-up
(candidate KB entry: a shared "resolve FFI library path" helper would also
fix the `.so`/`.dylib` problem in one place).

## What was rejected

- **Removing the else-branch outright.** Rejected — reasons above
  (documented hybrid intent, class-not-found API regression, unclear error).
- **Passing a hardcoded default path to `FfiEncoder`** (e.g.
  `new FfiEncoder(__DIR__ . '/../clib/build/libscanme_qr.so')`). Rejected:
  hardcodes the local-build path, ignores the vendor-binary location used by
  `QRCode`, and would produce the "Native library not found" RuntimeException
  from `FfiEncoder`'s constructor on any system without a local build —
  without telling the user how to fix it.
- **Extracting a shared library-path resolver (static helper or class).**
  Rejected: cleanest long-term, but out of scope for #39; would touch
  `QRCode`, `NativeEncoder`, and add a new class. Noted as follow-up.
- **Adding the `php-ext/scanme_qr.c` file to the consistency test.**
  Rejected: the test instruction named exactly three files
  (`QRCode`, `NativeEncoder`, `Composer/Plugin.php`); the C source is the
  source of truth but lives outside `src/`, and asserting on it would also
  require checking a different token (`"scanmeqr"` string literal vs
  `extension_loaded('scanmeqr')`). Noted in findings instead.
- **Delegating the fallback to a pure-PHP encoder (FastEncoder/Encoder).**
  Rejected: a class named `NativeEncoder` silently returning pure-PHP
  matrices would be misleading; its contract is "native when possible".

## Regression test choice

Single-source-of-truth string test (`tests/ExtensionNameConsistencyTest.php`),
not a stub/re-mocking test — a stub `EncoderInterface` would require changing
`createDefaultEncoder`'s signature or wiring (scope widening), and the
string assertion directly pins the string that regressed. Verified that the
test **fails against the pre-fix source** (run before applying the fix):
`QRCode.php` did not contain `extension_loaded('scanmeqr')`. A future typo
in any of the three files fails the suite. The second test
(`createDefaultEncoder()` does not return `NativeEncoder` when the extension
is absent) is a sanity check only; it does not by itself catch the #39
regression — it skips when `extension_loaded('scanmeqr')` is true and the
wrong-name bug was observable only with the extension loaded.

## What was uncertain

- Whether the else-branch fallback was reachable at all. It is not via
  `QRCode` (the `extension_loaded` gate plus `class_exists`), so it only
  matters for direct instantiation. This is exactly why the issue text
  allowed either keep-and-fix or removal; I chose keep-and-fix for the
  API-consistency and error-clarity arguments above.
- Whether the consistency test should assert on raw file contents (brittle
  if files get reorganized) or on a shared constant (would require a new
  abstraction). Chose raw contents: it is a regression pin for a string
  typo — exactly the failure mode of #39 — and the files are stable.
- Whether `ReflectionMethod::invoke` needs `setAccessible(true)` on
  PHP 8.2+. PHP 8.1+ makes private reflection invocation work without it;
  verified with `php -r` (local PHP 8.5.9). `setAccessible()` is not called
  to avoid the PHP 8.5 deprecation.
