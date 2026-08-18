# Findings — coder

Issue: #48 — native binaries downloaded by the Composer plugin without
checksum/signature verification → RCE on release-channel compromise.
Branch: `fix/issue-48-security-native-binaries-are-downloaded`

## Biggest problem faced

Two intertwined traps in the Plugin re-plumbing:

1. **The version source.** The old `Plugin::downloadBinary()` computed the
   release version from `$this->composer->getPackage()` — the **root
   project's** package — while the download URL must use the **scanmephp
   package's** own version. The duplicated logic made this easy to miss:
   the download worked "by accident" only when the root project had no own
   version (Composer defaults the root package version to `1.0.0` or
   `dev-*` — in many apps that already produced a wrong 404 URL). The fix
   threads the scanmephp `$version` already computed in `installBinaries()`
   down into both install paths instead of re-deriving it.
2. **The projectRoot question** (where `extra.scanmephp.checksums` lives).
   Cheap-looking but security-critical: a checksum that the attacker can
   rewrite together with the binary is worthless. Resolved by pinning to the
   consumer's root `composer.json` via
   `dirname($composer->getConfig()->get('vendor-dir'))` (Composer resolves
   `vendor-dir` absolutely against the root composer.json directory) and
   deliberately *not* falling back to the package's own composer.json in
   `vendor/`. Full reasoning in `code-decision-1.md`.

## Discovered bugs / places to improve

All itemized with file:line and a suggested fix. Only the first two are
fixed by this PR; the rest are out of scope, recorded for later.

### 1. Plugin downloaded against the ROOT project's version — FIXED in this PR
- `src/Composer/Plugin.php` (old `downloadBinary()`, ~line 225): version came
  from `$this->composer->getPackage()->getPrettyVersion()` instead of the
  scanmephp package. Any root project with its own `version` field got a
  wrong release URL (404 → download failed → silent FFI fallback).
- Fixed: version is threaded from `installBinaries()` where it is computed
  from the installed scanmephp package.

### 2. Duplicated platform detection — FIXED in this PR
- `src/Composer/Plugin.php` re-implemented `getOperatingSystem()`,
  `getArchitecture()`, `getLinuxVariant()`, `getFfiBinaryName()` that were
  byte-for-byte the same logic as `src/PlatformDetector.php`.
  `getFfiBinaryName()` was identical to `PlatformDetector::getBinaryName()`.
  Two copies of platform naming logic drift independently (they already
  diverged once, see FAQ-006). Plugin now delegates to `PlatformDetector`
  (ext-only `getPhpVersion()`/`getExtensionBinaryName()` stay in the Plugin —
  `PlatformDetector` has no equivalents).

### 3. chmod-before-verify ordering — FIXED in this PR
- `src/Composer/Plugin.php:273-275` (old code): `chmod(0755)` applied to the
  downloaded file with no integrity check at any point. `BinaryDownloader`
  already verified *before* `chmod();` routing everything through it removes
  the executable-unverified-binary window.

### 4. InstallScript: build-from-source fallback masks the checksum refusal — OUT OF SCOPE
- `src/Composer/InstallScript.php:96-118` — the catch-all
  `catch (\Exception $e)` treats `DownloadException::checksumMissing()` like
  a network failure, prints "Download failed: …", then "Attempting to build
  from source…". The user is never told the download was **refused** because
  no checksum is configured — with fail-closed downloads this will be the
  most common failure mode and the message is misleading.
- Suggested fix: catch `DownloadException::checksumMissing()` explicitly
  first, print the "add extra.scanmephp.checksums" hint and skip the
  build-from-source attempt (building from source does not resolve a missing
  checksum, and silently falling back to the pure-PHP encoder hides the
  security state). The build-from-source path itself is safe (local cmake
  build, no network, no unverified artifact).

### 5. Existing binaries are never re-verified — OUT OF SCOPE
- `src/Composer/Plugin.php` `installExtensionBinary()` /
  `installFfiBinary()`: when the target file already exists the plugin
  returns early (extension ~line 168-176, ffi ~line 283-289). A binary
  placed there by an **older, unverified** plugin version — or tampered with
  after install — stays accepted forever.
- Suggested fix: when a checksum is configured and the file exists, verify
  the on-disk file against it (`hash_file`) and refuse/redownload on
  mismatch; only skip verification when no checksum is known.

### 6. InstallScript finds the root by walking cwd — OUT OF SCOPE, low
- `src/Composer/InstallScript.php:151-164` (`findProjectRoot()`) walks up
  from `getcwd()`; if invoked from a subdirectory it can pick the wrong
  `composer.json`. The Plugin now uses `vendor-dir` config instead (reliable
  regardless of cwd). Suggested fix: have InstallScript prefer
  `$argv`-adjacent hints or document that the script must run from the
  project root.

### 7. Tests cannot exercise the happy download path offline — residual risk
- `tests/BinaryDownloaderTest.php` — the verified-success path (download →
  hash match → chmod) requires the GitHub release URL, so it is untested;
  the fail-closed paths are covered, plus the Plugin refuses-without-checksum
  wiring (`tests/Composer/PluginTest.php`). Suggested future work: a local
  HTTP fixture (e.g. `php -S` serving a known file) to test download +
  checksum-match + checksum-mismatch end to end without the network.

### 8. Deprecations in QrReferenceTest (pre-existing, unrelated) — already tracked
- `tests/QrReferenceTest.php:46-47` — `fgetcsv()` without `$escape`
  triggers deprecation on PHP 8.4+. Already tracked as #40; run the suite on
  PHP 8.5 and it shows 8 deprecations. Untouched.

## Notes on test/lint results

- `composer test`: 5396 tests, 11508 assertions — OK (1 pre-existing skip,
  8 pre-existing deprecations from #40).
- `composer lint`: php-cs-fixer 0 fixes, phpstan no errors, rector OK,
  kb-lint ok.
