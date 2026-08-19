# Review Round 2 — issue #185 (security: existing binaries on disk are never re-verified against checksums)

**Branch:** `fix/issue-185-security-existing-binaries-on-disk-are`
**Commit:** 4833955 (round-1 fixes; `src/Composer/Plugin.php`, `tests/Composer/PluginTest.php`, `tests/ChecksumManagerTest.php`, `CHANGELOG.md`)
**Reviewer:** review-critical (round 2)
**Date:** 2026-08-19

## Overall verdict: CLEAN

No blockers, no security findings. All six round-1 findings are resolved or
accepted-pending; two new findings (F-7 low, F-8 nit) concern **test-seam
fidelity only** — neither affects product behavior or security, and no gate
lowering is proposed or needed. Fail-closed properties re-verified intact:
mismatch → warning → `@unlink` → verified re-download; directory at target →
clean single failure; no-checksum legacy path unchanged.

## Round-1 finding re-checks (F-1…F-6)

| # | severity | disposition claimed | verified status | evidence |
|---|----------|--------------------|-----------------|----------|
| F-1 | medium | fixed | **fixed (with 2 fidelity caveats → F-8)** | Seam added: `Plugin::createDownloader()` now `protected` (Plugin.php:262); `StubBinaryDownloader` + `StubDownloaderPlugin` at the bottom of PluginTest.php. `testPackageInstallReplacesBinaryWhenExistingChecksumMismatches` (PluginTest.php:108-139) runs offline, passes (test run: 13 tests/30 assertions in touched files), and **does catch the fail-open regression** (blind accept → no warning, 0 downloader calls → two failing assertions). Caveats: the "unlinked" half of the disposition is **not** actually asserted — `file_put_contents` overwrites, so all four assertions pass even if `@unlink` is removed from Plugin.php:160 — and the stub never verifies: it writes `re-downloaded-verified-content` while the pinned checksum is `sha256('verified-binary-content')`, a state the real downloader would reject (checksumMismatch → unlink → fallback). |
| F-2 | low | fixed | **fixed** | `is_file($targetFile)` at Plugin.php:148 (ext) **and** :221 (FFI) — both install paths confirmed. `testPackageInstallWithDirectoryAtTargetPathFailsCleanly` (PluginTest.php:141-173) passes **without warnings**: no verification warning, no unlink attempt, directory left untouched, accurate "Extension download failed: …cannot write…" message. Empirically confirmed via test run plus a standalone probe in TMPDIR (repo not modified). |
| F-3 | low | fixed | **fixed** | Warning now printed **before** `@unlink` in both paths (Plugin.php:159-160, :227-230→:230-231). `@` suppression has in-tree precedent (`@file_get_contents` PlatformDetector.php:43, `@hash_file` ChecksumManager.php:64); failure is benign-in-effect (traced round 1: failed unlink can never lead to accepting the mismatched file). PHPStan level 4 passes with the suppression. |
| F-4 | low (KB) | fixed by main session at step 14 | **still present in KB — accepted-pending** | Re-verified with `gh issue view`: **#185** = "security: existing binaries on disk are never re-verified against checksums" (this PR), **#182** = "security: add signature verification (minisign/cosign/GPG)". `.workflow/helpers/decisions.md:97-100` (DEC-006) still swaps the numbers and the "#182 accepted without re-verification" sentence does go stale on merge. Per single-writer rule this is the main session's step-14 action; treated as accepted-pending, not re-counted as open. |
| F-5 | nit | fixed | **fixed** | Renamed `testExistingBinaryIsInvalidWhenFileMissing` (tests/ChecksumManagerTest.php:210). Honest scope; the missing-file case covers the same `@hash_file → false` branch. |
| F-6 | nit | fixed | **fixed** | CHANGELOG entry under `### Fixed` (CHANGELOG.md:63), matching #48/#57 convention; still under `## [Unreleased]`, content unchanged and correct. |

## New findings (commit 4833955)

### F-7 — Stub seam ignores `createDownloader()`'s target directory; FFI-enabled environments exercise a distorted flow | severity: low | status: open
**Where:** `tests/Composer/PluginTest.php:274-296` (`StubBinaryDownloader::download` uses the fixed `$this->dir`), interacting with `Plugin.php:118-119` (ext failure → FFI fallback) and `Plugin.php:172/243` (`createDownloader($binaryPath, …)` passes the *call-site* directory, which the stub discards).
**What is wrong:** The stub stores its directory once (constructor arg) and ignores the `$binaryPath` the plugin passes per call site. When ext-ffi is loaded — and FAQ-003 documents CI runs `extensions: ffi, gd`, so **every CI leg** — the directory test's ext-install failure falls through to `installFfiBinary()`, which invokes the same stub with the `ffi-binaries` path. The stub then writes the FFI binary into `ext-binaries/` and returns success; the plugin prints "✓ FFI library downloaded successfully to: …/ffi-binaries/libscanme_qr-….dylib" for a file that **does not exist**. Confirmed empirically on this machine (ffi loaded): `downloadCalls = 2`, `ffi-binaries/` empty, stray `libscanme_qr-macos-arm64.dylib` in `ext-binaries/`. The test still passes because `assertGreaterThanOrEqual(1, …)` (PluginTest.php:171) tolerates 1 (no ffi) vs 2 (ffi) — but the test's meaning silently depends on the environment and its FFI "success" branch is fiction. No false pass/fail today; a later assertion on ffi-binaries content would break confusingly.
**Why it is not a blocker:** no security impact, test remains green and still pins the F-2 behavior it targets (is_file guard, no warning, dir untouched).
**Smallest safe fix:** assert exactly `1` and `markTestSkipped` when `extension_loaded('ffi')` (mirroring the existing scanmeqr skip), or make the stub honor the directory argument from `createDownloader()` (record per-call dirs and assert the FFI call targeted ffi-binaries).
**Automated check:** the PHPUnit assertion change itself (`=== 1` + environment skip).

### F-8 — Mismatch test does not prove the unlink; stub writes content the real checksum path would reject | severity: nit | status: open
**Where:** `tests/Composer/PluginTest.php:120` (stub content `re-downloaded-verified-content`) vs `:128` (pinned checksum = `sha256('verified-binary-content')`); assertion at `:138`.
**What is wrong:** (a) the disposition says the test asserts "tampered file → **unlinked** + warning + downloader invoked + file replaced" — the unlink is not verified: `file_put_contents` truncates/overwrites, so deleting `@unlink($targetFile)` from Plugin.php:160 leaves all four assertions green. (b) The stub marks its output as "pre-verified content" (docblock, PluginTest.php:272-273) but neither checks nor matches the pinned checksum — with the real downloader this exact input ends in `checksumMismatch` → unlink → graceful fallback, so the asserted happy-end ("file replaced") is **unreachable in production** for these checksum/content values. The fail-open regression IS still caught (that part of F-1 is genuinely fixed); this is precision of the pin, not absence of one.
**Smallest safe fix:** shape the test as round-1 F-1 originally suggested: stub throws `DownloadException::downloadFailed` → assert target file is **absent** (proves unlink), fallback message present, exactly-once invocation; or make the stub refuse to write when the target still exists.
**Automated check:** the strengthened assertions above.

## Clean checks (task items)

- **`is_file()` in both install paths** — Plugin.php:148 and :221 ✔ (F-2 verified).
- **`@unlink` / `@file_put_contents` suppressions** — precedent in-tree (PlatformDetector.php:43, ChecksumManager.php:64; stub's `@` mirrors the fail-closed `@hash_file` pattern); PHPStan level 4 OK; no new baseline entries, no `@phpstan-ignore`.
- **PSR-12** — php-cs-fixer: 0/55 files need fixing.
- **`require` untouched** — `git diff origin/main...HEAD -- composer.json` empty (FAQ-004 clean).
- **KB sweep via tag index** (`security`, `composer`, `ffi`, `exception`, `tests`, `phpstan` → DEC-001/002/003/004/005/006, FAQ-001/003/004/005/006/007/008/009): the only violation flagged is DEC-006 (F-4, accepted-pending). No new FAQ-007 message-comparison sites; no FAQ-008/009 Builder changes; new tests skip when `scanmeqr` loaded, per FAQ-003; no `.so` hardcoding (FAQ-006 clean).
- **Test seam scrutiny** — no order-dependence (uniqid temp dirs, per-test setUp/tearDown); Windows-safe (`/` concatenation + platform `match` arms); environment-dependence limited to F-7; PHP 8.2-safe syntax (readonly promotion, `??=`, `match` — all 8.2+).
- **No new runtime deps, no CHANGELOG structure drift** — `### Fixed` per AGENTS.md list.

## Gates

| Command | Result |
|---------|--------|
| `composer lint` | passed — cs-fixer 0/55, PHPStan `[OK] No errors`, rector OK, kb-lint 0 warnings |
| `composer test` (full) | passed — 5411 tests, 11565 assertions, exit 0 (ran twice, stable). 8 fgetcsv deprecations pre-existing (QrReferenceTest, PHP 8.5); 1 PHPUnit deprecation pre-existing (BuilderTest doc-comment metadata, from #57 — file untouched by this branch); 1 skip pre-existing (FAQ-001 platform library) |
| Touched files (`PluginTest.php` + `ChecksumManagerTest.php`) | OK — 13 tests, 30 assertions, no deprecations |
| Standalone probe (TMPDIR, repo read-only) | reproduced ffi-environment branch of directory test: downloadCalls=2, FFI "success" written to wrong dir, ffi-binaries empty |
| `gh issue view 185 / 182` | #185 = re-verification (this PR), #182 = signature verification — DEC-006 numbers confirmed swapped (F-4) |

## Candidate knowledge-base entries (proposed; single-writer rule — main session decides at step 14)

1. **Update DEC-006** (as dispositioned in round 1 — re-confirmed against `gh issue view`): #185 = on-disk re-verification (implemented fail-closed by this PR), #182 = signature verification (open). Also note post-merge that on-disk re-verification now happens for both ext and FFI paths with `is_file()` guards.
2. **New FAQ-010 — "Stubbed downloaders in Composer-plugin tests: honor the seam's directory and fail, don't overwrite"**. *Tags:* tests composer security. *Trigger:* writing a stub for `BinaryDownloader` / overriding `createDownloader()` in a Composer plugin test. *Body:* (a) a success-writing stub cannot prove the code under test unlinked the old file — `file_put_contents` (and the real `fopen('wb')`) overwrite, so the replace assertion passes with or without the unlink; to pin unlink, stub throws `DownloadException::downloadFailed` and assert the target is absent + the fallback message. (b) Honor the `$binaryPath` argument: the FFI fallback path (Plugin.php:118-119) reuses the same `createDownloader()` seam with a *different* directory — a stub hardwired to the ext dir writes FFI "downloads" into the wrong place and reports a success message for a file that doesn't exist. (c) CI enables `ffi` (FAQ-003), so ext-failure tests always fall through to the FFI path there (2 stub calls) while local machines without ffi see 1 — pin exact call counts together with an `extension_loaded('ffi')` skip, or the assertion must be loose and the flow divergent by environment.

## Commands run

See Gates table; full log artifacts in session. Repo stayed read-only: the only writes were the two review files and throwaway probe scripts in the OS temp dir.
