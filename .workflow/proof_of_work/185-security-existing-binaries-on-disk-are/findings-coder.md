# Findings — coder

Issue: #185 — Composer plugin accepts already-present binaries on disk without
re-verification against consumer-pinned SHA-256 checksums.
Branch: `fix/issue-185-security-existing-binaries-on-disk-are`

## Biggest problem faced

**Testing the mismatch path without making the test suite hit the network.**
The mismatch branch's whole point is "unlink and re-download through the
verified path" — and the verified path is a real cURL download from
`github.com/crazy-goat/ScanMePHP/releases`. Two traps:

1. The *decision* (is the on-disk file acceptable?) is trivially unit-testable
   — I moved it into `ChecksumManager::existingBinaryIsValid()` so the 4
   decision cases (match / mismatch / no checksum / unhashable) are covered
   with the existing temp-dir fixture pattern, zero Composer mocks.
2. The *orchestration* (unlink + rejoin download path) cannot be exercised
   offline as a permanent test without adding a mock seam (e.g. a
   `createDownloader()` override — rejected as over-engineering; `Plugin`
   has no tests per #128 precisely because of this wiring). I verified it
   end-to-end with a **throwaway** test (tampered FFI binary → "failed
   SHA-256 verification" message → file unlinked → real download attempted →
   `checksumMismatch` rejection because the pinned checksum was my fake one →
   graceful pure-PHP fallback), then deleted the test — because in CI it
   would download a real binary and eat ~1s of network per run, and would
   break in offline environments.

The committed `PluginTest` additions instead pin the two *network-free*
fast paths end-to-end (matching-checksum "already exists" with the file bit
for bit untouched; no-checksum legacy acceptance). Both are deliberately
constructed to fail loudly **without** network access if the plugin's binary
naming ever changes: a stale name means the plugin looks up a checksum (or a
file) it cannot find, and the fail-closed refusal path fires before any HTTP
request. This required duplicating `Plugin::getExtensionBinaryName()`'s
6-line `match` in the test (deliberately; see Finding 2).

## Discovered bugs / places to improve

### 1. `curl_close()` emits a deprecation on PHP 8.5 — out of scope
- `src/BinaryDownloader.php:86` — `curl_close($ch)` in the `finally` block.
  `curl_close()` has been a no-op since PHP 8.0 and is **deprecated since
  PHP 8.5**; my smoke test on PHP 8.5.9 triggered
  `Function curl_close() is deprecated since 8.5, as it has no effect since PHP 8.0`.
- Suggested fix: delete the `curl_close($ch)` line (the handle is freed when
  the variable goes out of scope). CI is PHP 8.2–8.4 so it is not failing
  there yet, but any PHP 8.5 consumer hits the deprecation on every install.

### 2. `Plugin::getExtensionBinaryName()` is private and duplicated in tests — out of scope
- `src/Composer/Plugin.php:268-276` — the ext naming `match` is the last
  platform-naming logic that lives in the Plugin (everything else was moved
  to `PlatformDetector` in #48). My `PluginTest::extensionBinaryName()`
  mirrors it on purpose; that duplication will drift if the naming ever
  changes (the test fails loudly, but still: two sources of truth — the exact
  bug #48 fixed for the FFI names).
- Suggested fix: move to `PlatformDetector::getExtensionBinaryName($os, $variant, $arch, $phpVersion)`
  in a follow-up; test then calls the public API and the pin is free.

### 3. Legacy `InstallScript` still accepts any existing binary — out of scope, arguably a real gap
- `src/Composer/InstallScript.php:58-62` — `if (file_exists($targetFile)) {
  echo "✓ Binary already exists…"; return; }` — same unverified acceptance
  this issue fixes in the Plugin. `InstallScript` is superseded
  (composer.json `extra.class` → `Composer\Plugin`), so today it appears to
  be dead code reachable only through ancient installs/consumers that pinned
  the old `post-install-cmd` route. I did not change it (issue scope is
  `Plugin.php`), but a follower-up should either delete `InstallScript`
  entirely (and its tests, if any) or route it through the same
  `existingBinaryIsValid()` check.
- Also noteworthy: `InstallScript` still has the old "download → build from
  source" dual path and does **not** use the fail-closed `BinaryDownloader`
  checksum refusal message flow — it catches `\Exception` broadly and falls
  into the `Builder`, which contradicts the #48 fail-closed model if it is
  ever resurrected.

### 4. Runtime FFI library load still trusts `vendor/` contents — out of scope, by design after #48
- `src/FfiEncoder.php:76` — `extension_loaded('ffi') && file_exists($libraryPath)`
  accepts any present library at encode time; verification happens once at
  install time (now re-verified per #185 when a checksum is pinned), so a
  binary swapped after install with filesystem write access to `vendor/` is
  still loaded. The issue itself rates this acceptable (attacker with write
  access to `vendor/` already has RCE), and per-request hashing would add
  overhead — but a one-time-verified-cache or lazy verification (e.g. hash
  once per process or when mtime changes) would close even that gap.

### 5. Plugin catch blocks compare exception messages by string equality — tracked by #184
- `src/Composer/Plugin.php:176` and `:234` — `$e->getMessage() ===
  DownloadException::checksumMissing($binaryName)->getMessage()` is the
  fragile pattern FAQ-007 warns about; a future message-format change
  silently flips consumers into the wrong fallback branch. Already tracked
  by #184, untouched here.

### 6. Plugin test suite runs @ PHP 8.5 locally — CI gap
- Local PHP is 8.5.9 (FFI loaded, `scanmeqr` not loaded); CI matrix is
  8.2–8.4 and does not include 8.5, so `curl_close`-style deprecations pass
  undetected until consumers hit them. Consider adding 8.5 (or `php -d
  error_reporting=E_ALL` deprecation gate in CI) once supported by the dev
  toolchain (PHPUnit ^11.5 supports 8.5? — verify; otherwise a temporary
  deprecation silencer in the test bootstrap is not worth it).

## Test results summary

- `vendor/bin/phpunit tests/ChecksumManagerTest.php tests/Composer/PluginTest.php` — **OK (11 tests, 21 assertions)** — new: 4 ChecksumManager decision cases + 2 Plugin end-to-end fast-path tests (matching checksum; no checksum).
- Throwaway network smoke test (deleted before commit): mismatch → unlink → re-download → checksum rejection → graceful fallback, verified manually on PHP 8.5.9/macOS arm64.
- Full suite: see `composer test` run in the report.
