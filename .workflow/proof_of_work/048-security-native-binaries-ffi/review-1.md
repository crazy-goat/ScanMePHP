# Review — round 1 (review-critical)

Issue: #48 — native binaries downloaded by the Composer plugin without
checksum/signature verification → RCE on release-channel compromise.
Branch: `fix/issue-48-security-native-binaries-are-downloaded`
Reviewer: review-critical agent
Date: 2026-08-18

## Verdict: APPROVE (with follow-up findings)

The fail-closed guarantee is **airtight**. Every code path that downloads a
native binary routes through `BinaryDownloader::download()`, which throws
`DownloadException::checksumMissing()` **before any HTTP request or file
creation** when no checksum can be verified. The trust anchor is correctly
the consumer's root `composer.json`, resolved via
`dirname($composer->getConfig()->get('vendor-dir'))`. Checksum verification
happens before `chmod`. The version source is the scanmephp package (not the
root project). No gates were lowered (PHPStan baseline, coverage, CI all
untouched). Zero runtime dependencies preserved.

The findings below are observability/UX and transitional-safety issues, not
security holes. The fail-closed invariant holds on every path.

---

## Findings

### Finding 1 — MEDIUM: Plugin labels security refusal as "download failed"

**File:** `src/Composer/Plugin.php:168-171` (extension), `Plugin.php:223-226` (FFI)

**What is wrong:** Both `installExtensionBinary()` and `installFfiBinary()`
catch `\Exception` generically and print `"⚠️ Extension download failed: "`
/ `"⚠️ FFI library download failed: "` for all exceptions, including
`DownloadException::checksumMissing()`. The full exception message IS printed
(it contains "Download refused — add extra.scanmephp.checksums to the root
composer.json"), so the refusal is **not silent**. However, the framing as
"download failed, falling back to FFI/pure PHP" is misleading: the download
was not a network failure, it was a deliberate security refusal, and the
"falling back" language implies a transient issue rather than a required
configuration action.

When no checksums are configured, the extension download is refused → caught
→ "falling back to FFI" → FFI download also refused → caught → "pure PHP
encoder will be used instead." The user sees the security message twice but
framed as failure, and may not realize they need to add
`extra.scanmephp.checksums` to their root `composer.json`.

**Impact:** Observability/UX, not security. No unverified binary is ever
downloaded or executed — fail-closed holds. But the most common failure mode
with this fix (no checksums configured) produces misleading output that
doesn't clearly communicate the required remediation step. A user may ignore
the warning and operate without native binaries indefinitely without
understanding why.

**Smallest safe fix direction:** Catch `DownloadException::checksumMissing()`
explicitly before the generic `catch (\Exception)` in both methods. Print a
clear, actionable message ("Binary download refused: no SHA-256 checksum
configured. Add `extra.scanmephp.checksums` to your root composer.json to
enable verified downloads.") and skip the "falling back" language, since the
next install method will also be refused for the same reason. This is the
same class of issue as coder finding #4 (InstallScript build-from-source
fallback masks the refusal).

**Could an automated check catch this?** A test could assert the Plugin
output contains "refused" or "add extra.scanmephp.checksums" (not just
"checksum") when no checksums are configured — strengthening the existing
`assertStringContainsString('checksum', ...)` assertion in `PluginTest`.
This is a test-level check, not a static analysis rule.

**Status:** open — recommended follow-up, not a blocker for this PR.

---

### Finding 2 — LOW: Existing binaries on disk accepted without re-verification

**File:** `src/Composer/Plugin.php:153-156` (extension), `Plugin.php:204-207` (FFI)

**What is wrong:** When the target binary file already exists on disk, the
Plugin returns early without verifying its checksum. A binary placed by an
older, unverified plugin version (before this fix) — or tampered with after
install — stays accepted forever.

**Impact:** Transitional risk for existing installations that had binaries
downloaded by the old unverified plugin. The binary is not re-downloaded, so
a previously-installed malicious binary persists. However:
- An attacker with filesystem write access to `vendor/` already has arbitrary
  code execution — verifying the file wouldn't help against that.
- The realistic scenario is an old binary from a pre-fix plugin version. This
  is a migration concern, not an ongoing attack surface.
- New installs are fully protected by the fail-closed download path.

**Smallest safe fix direction:** When a checksum IS configured and the file
exists, verify the on-disk file with `hash_file('sha256', $targetPath)` and
refuse/re-download on mismatch. Only skip verification when no checksum is
known. This is coder finding #5, correctly marked out of scope.

**Status:** open — legitimate follow-up, not a blocker for THIS PR. The PR's
scope is the download path; existing-binary verification is a separate,
valuable enhancement that should be tracked as a follow-up issue.

---

### Finding 3 — NIT: `ltrim($version, 'v')` strips all leading 'v' chars

**File:** `src/ChecksumManager.php:42`

**What is wrong:** `ltrim($version, 'v')` strips ALL leading 'v' characters,
not just one. For versions validated by the regex in `BinaryDownloader`
(`/^v?\d+\.\d+\.\d+$/`), there is at most one 'v' prefix, so this is
harmless. It is semantically imprecise — a single-'v' strip would be clearer.

**Impact:** None in practice. The regex prevents multi-'v' versions from
reaching `ChecksumManager` through normal code paths. No collision or bypass
is possible.

**Smallest safe fix direction:** None needed, or use
`str_starts_with($version, 'v') ? substr($version, 1) : $version` for
clarity.

**Status:** open — nit, no action required.

---

## High-risk areas checked clean

### Fail-closed guarantee (THE security question)

**`BinaryDownloader::download()`** — Traced every path:
- `$expectedChecksum === null` + no manager → `throw checksumMissing()` ✓
- `$expectedChecksum === null` + manager but `hasChecksum()` false → `throw checksumMissing()` ✓
- `$expectedChecksum === null` + manager + `hasChecksum()` true → checksum retrieved, verified ✓
- `$expectedChecksum` provided (non-null) → skip fail-closed block, verify against provided value ✓
- After the fail-closed block, `$expectedChecksum` is guaranteed non-null (because `hasChecksum()` calls `getChecksum()` and checks `!== null`; both read from the same in-memory array loaded once in constructor — no TOCTOU) ✓
- Verification is unconditional (the old `if ($expectedChecksum !== null)` guard is removed) ✓
- On mismatch: `unlink($targetPath)` + throw, before `chmod()` ✓
- `hash_file()` returning `false` (edge case): `false !== $expectedChecksum` → true → throw (fail-closed) ✓
- Empty string / `false` / `0` / `true` as checksum value: all fail verification (non-matching type/value) ✓

**`BinaryDownloader::downloadForCurrentPlatform()`** — delegates to `download()` with same `$expectedChecksum`. If null, `download()` does the fail-closed check. ✓

**`Plugin::installExtensionBinary()`** — all download paths go through `$this->createDownloader(...)->download($binaryName)`. No direct cURL. No bypass. ✓

**`Plugin::installFfiBinary()`** — same: `$this->createDownloader(...)->download($binaryName)`. ✓

**`Plugin::createDownloader()`** — always passes `ChecksumManager` (never null). ✓

### Trust anchor correctness (THE other security question)

`Plugin::getProjectRoot()` returns `dirname((string) $this->composer->getConfig()->get('vendor-dir'))`.

- `$this->composer` is the instance from `activate()` — the ROOT project's Composer instance.
- `getConfig()->get('vendor-dir')` returns the absolute vendor directory path (Composer resolves path settings against the root `composer.json` directory).
- `dirname()` of the vendor dir gives the consumer's project root.
- `ChecksumManager` reads `$projectRoot . '/composer.json'` — the CONSUMER's root, NOT the package's own `vendor/crazy-goat/scanmephp/composer.json`.
- An attacker who takes over the release channel can swap the binary AND the package's own composer.json, but NOT the consumer's root composer.json. The consumer-pinned checksum is the trust anchor. ✓
- No fallback to the package's own composer.json (explicitly rejected in code-decision-1.md). ✓

Edge case: non-standard `vendor-dir` configured as an absolute path outside the project root (e.g., `/shared/vendor`). `dirname('/shared/vendor')` = `/shared`, not the project root. `ChecksumManager` would read `/shared/composer.json` — likely no checksums there → fail-closed (download refused). This is a usability issue for unusual configs, not a security issue. An attacker would need to control the consumer's composer.json (which grants RCE anyway) to exploit this. ✓

### chmod ordering

`BinaryDownloader::download()`: download → `hash_file` verify → `chmod`. On mismatch: `unlink` before `chmod`. No `chmod` on unverified files. ✓
`Plugin`: no `chmod` calls (all delegated to `BinaryDownloader`). ✓
`InstallScript`: `chmod` on locally-built binary (build-from-source path, no download) — safe. ✓

### Version source

`Plugin::installBinaries()`: `$version = ltrim($package->getPrettyVersion(), 'v')` where `$package` is the scanmephp package from the Composer operation (not `$this->composer->getPackage()` which would be the root project). This is the version whose release tag the URL must use. The old code used the root project's version — fixed in this PR. ✓

Version spoofing: an attacker would need to compromise Packagist/GitHub to publish a different version, but then the consumer-pinned checksum wouldn't match. The version is used both for the URL AND the checksum lookup — same version for both, so you can't point at a different release without also having the checksum for that release. ✓

### ChecksumManager prefix normalization

Three lookups: `$version`, `'v' . ltrim($version, 'v')`, `ltrim($version, 'v')`. This normalizes `v0.4.4` ↔ `0.4.4`. It is strict (exact string key match, no fuzzy/semver comparison), deterministic (first match wins), and cannot be abused to bypass — an attacker would need to write to the consumer's root composer.json to inject a checksum. No collision: having both `v0.4.4` and `0.4.4` keys is consumer misconfiguration; the first matching key wins. ✓

### Error handling — is `checksumMissing` swallowed?

**In Plugin:** caught by `catch (\Exception $e)` in both install methods. The exception message IS printed (contains "Download refused — add extra.scanmephp.checksums"). Not silent, but framed as "download failed" (Finding 1). No unverified binary is loaded. ✓

**In InstallScript (unchanged, coder finding #4):** caught by `catch (\Exception $e)`, prints "Download failed: ..." then "Attempting to build from source...". The build-from-source path is safe (local cmake build, no network, no unverified artifact). Misleading UX but not a security hole. Out of scope for this PR. ✓

### Zero runtime deps

`composer.json` `require` unchanged: `php: ^8.2`, `composer-plugin-api: ^2.0`. No new runtime dependencies. cURL usage is pre-existing (ext-curl is not in `require` but was already used before this PR). ✓ (FAQ-004)

### No gate lowered

- `phpstan-baseline.neon`: untouched ✓ (DEC-005)
- `phpstan.neon.dist`: untouched ✓
- CI workflows (`.github/`): untouched ✓
- `phpunit.xml`: untouched ✓
- No test disabled or skipped ✓
- No coverage floor changed ✓ (FAQ-002)

### Type correctness / PSR-12 / strict_types

All new and modified files have `declare(strict_types=1)`. Return types declared. Readonly properties used. No unused imports (new imports in Plugin: `BinaryDownloader`, `ChecksumManager`, `PlatformDetector` — all used). PHPStan level 4: no errors. ✓

### Tests

- `testDownloadThrowsChecksumMissingWhenNotConfigured` — asserts throw + no file created ✓
- `testDownloadThrowsChecksumMissingWithoutManagerAndNoExplicitChecksum` — asserts throw ✓
- `testGetChecksumIgnoresVPrefixMismatch` — asserts v-prefix normalization ✓
- `testHasChecksumResolvesUnprefixedKeys` — asserts reverse normalization ✓
- `testPackageInstallRefusesBinaryDownloadWithoutChecksums` — asserts Plugin output contains "checksum" + no binary files created ✓

Gaps (known, not blockers):
- Happy download path (download → hash match → chmod) untested — requires network or local HTTP fixture (coder finding #7). Pre-existing gap, not new.
- Checksum mismatch path (download succeeds, hash doesn't match) untested — pre-existing, the behavior is unchanged from the existing code (just made unconditional).
- `PluginTest` assertion is conditional on `!extension_loaded('scanmeqr')` — defensive; `scanmeqr` is not loaded in CI. The assertion could be strengthened to check for "refused" or "add extra.scanmephp.checksums" (Finding 1).

### Commit message

`fix(composer): verify native binary checksums before FFI load (closes #48)`
- Type: `fix` ✓
- Scope: `(composer)` — maps to `src/Composer/`, the primary change site ✓ (DEC-002)
- Subject: descriptive ✓
- Issue ref: `(closes #48)` ✓

### CHANGELOG

Entry under `[Unreleased]` → `### Fixed`, accurately describes the fail-closed change. ✓

---

## Candidate knowledge-base entries

### Candidate 1: "Composer plugin binary download is fail-closed"
- **Tags:** `ffi`, `composer`, `security`
- **Trigger:** working on `BinaryDownloader`, `Plugin`, or `InstallScript` download paths; questions about checksum verification or "why does composer install refuse to download binaries"
- **Body:** The Composer plugin (`src/Composer/Plugin.php`) routes all native binary downloads through `BinaryDownloader::download()`, which is fail-closed: if no SHA-256 checksum can be determined (either via an explicit `$expectedChecksum` argument or via `ChecksumManager` reading `extra.scanmephp.checksums` from the consumer's root `composer.json`), it throws `DownloadException::checksumMissing()` before any HTTP request or file creation. Checksums are read from the consumer's root `composer.json` (resolved via `dirname($composer->getConfig()->get('vendor-dir'))`), NOT the package's own `vendor/crazy-goat/scanmephp/composer.json` — the consumer is the trust anchor. Verification happens before `chmod 0755`. Existing binaries on disk are currently accepted without re-verification (follow-up). Signature verification (minisign/cosign) is a known future enhancement.

### Candidate 2: "ChecksumManager version key normalization (v-prefix)"
- **Tags:** `composer`, `checksums`
- **Trigger:** questions about ChecksumManager version key format, "checksum not found" when the version has/lacks a 'v' prefix
- **Body:** `ChecksumManager::getChecksum()` normalizes the `v` prefix on version keys: it tries the version as-is, then with a `v` prefix, then without. This means `extra.scanmephp.checksums` can be keyed as either `"0.4.4"` or `"v0.4.4"` and both will match a lookup for either form. The first matching key wins (deterministic). Having both keys with different checksums is consumer misconfiguration.
