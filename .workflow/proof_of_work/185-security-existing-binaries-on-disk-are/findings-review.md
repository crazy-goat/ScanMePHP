# Findings (review) — issue #185

Round 1 review by review-critical. **Note: this file did not exist before this
round** — it is created by this review (as was the case for the #048/#057
cycles). Verdict: APPROVE-WITH-NITS — no blockers, no security bypass found.
Six findings below (1 medium, 3 low, 2 nit).

| # | file:line | what is wrong | severity | status | automated check that could catch it |
|---|-----------|---------------|----------|--------|-------------------------------------|
| F-1 | src/Composer/Plugin.php:157-161, :228-232 (orchestration); missing test in tests/Composer/PluginTest.php | The mismatch branch this issue is about — unlink + fall-through into the verified re-download — has no committed regression test (coder verified it once with a throwaway networked test, then deleted it). A future refactor that reintroduces the blind "already exists → return" would merge with a green suite. Decision logic is pinned; orchestration is not. | medium | open | PHPUnit test via a small test seam (protected `createDownloader()` override or injectable downloader factory), asserting tampered file → unlinked + "Re-downloading" warning + downloader invoked + graceful failure message — offline, deterministic |
| F-2 | src/Composer/Plugin.php:148, :221 (`file_exists($targetFile)`) + src/ChecksumManager.php:64 | `file_exists()` is true for directories. Empirically verified: a directory at the target path → `existingBinaryIsValid()` false (correct) → `unlink()` fails with unsuppressed PHP warning ("Is a directory" / "Operation not permitted") → misleading "failed SHA-256 verification" message (nothing was verified; path is not a file) → download fails at `fopen('wb')` → the directory is never removed, so every subsequent install repeats the failure (native-binary install DoS that survives retries). Fail-closed still holds — no insecure acceptance; robustness/UX only. | low | open | PHPUnit test planting a directory at the target path and asserting a graceful single failure |
| F-3 | src/Composer/Plugin.php:159-160, :230-231 | Warning is printed *after* `unlink()`; an unlink failure is invisible (unsuppressed PHP warning + "Re-downloading…" followed by unexplained download failure). No security impact — traced: a failed unlink can never lead to accepting the mismatched file (either replaced by a verified file or the download fails loudly). | low | open | none reasonable (diagnostic quality) |
| F-4 | .workflow/helpers/decisions.md:98-100 (DEC-006) | Issue numbers swapped: DEC-006 attributes #185 to signature verification and #182 to on-disk re-verification; per `gh issue view`, **#185 is the re-verification issue this PR implements** and **#182 is signature verification**. The sentence "Existing on-disk binaries are currently accepted without re-verification (#182)" also goes stale on merge. KB-only; per single-writer rule the main session applies it. | low | open (KB) | none (prose cannot be linted for issue-number accuracy) |
| F-5 | tests/ChecksumManagerTest.php:210 | Test name `testExistingBinaryIsInvalidWhenFileMissingOrUnreadable` tests only the missing-file case; the unreadable case is not exercised (not portable: root ignores chmod 000, Windows differs). The missing case does cover the same `@hash_file → false` branch. | nit | open | none (naming) |
| F-6 | CHANGELOG.md:63-69 | `### Security` section is Keep-a-Changelog-legal but not in AGENTS.md's documented list (Added/Changed/Fixed/Removed); the two prior security fixes (#48, #57) went under `### Fixed`. Entry content itself is correct and complete. | nit | open | none (style) |

## Notes

- Findings-review.md did not exist before this round — the round-1
  findings/review files were the first artifacts written by review-critical
  (the coder's `findings-coder.md` and `code-decision-1.md` were already in
  place).
- High-risk areas verified clean: fail-closed on mismatch/unhashable/vanished
  file; no-checksum legacy path (regression-pinned by tests); checksumMissing
  catch branches (Plugin.php:183, 248) unreachable from the new mismatch flow —
  no new FAQ-007 message-comparison instances; zero runtime deps (composer.json
  untouched); TOCTOU #65 unchanged in scope (the `@hash_file` degradation is
  quiet fail-closed); all gates pass (`composer lint`, `composer test`,
  touched-file PHPUnit run: 11 tests / 21 assertions, no network, no
  deprecations).
- Out-of-scope items acknowledged by the coder and not re-counted as new
  findings: `InstallScript` legacy acceptance path (coder Finding 3), runtime
  `FfiEncoder` trust of `vendor/` after install (coder Finding 4, tracked by
  #65/#182), message-equality catches (coder Finding 5, tracked by #184),
  curl_close PHP 8.5 deprecation (coder Finding 1), private naming match
  duplication (coder Finding 2).
- Candidate KB entries proposed in review-1.md § Candidate knowledge-base
  entries: DEC-006 update (corrected issue numbers + post-merge status), and a
  new FAQ on `is_file()` vs `file_exists()` before unlink/hash.

## Round 1 dispositions (main session, after fixes)

| # | severity | disposition |
|---|----------|-------------|
| F-1 | medium | **fixed** — `Plugin::createDownloader()` is now `protected` (test seam); added `testPackageInstallReplacesBinaryWhenExistingChecksumMismatches` (tampered file → warning → unlink → stub downloader invoked exactly once → file replaced offline) using `StubBinaryDownloader`/`StubDownloaderPlugin` in the test file |
| F-2 | low | **fixed** — `file_exists()` → `is_file()` at Plugin.php:148, :221; added `testPackageInstallWithDirectoryAtTargetPathFailsCleanly` (directory at target path → no verification warning, no unlink attempt, graceful "download failed" fallback, directory left untouched) |
| F-3 | low | **fixed** — warning is now printed *before* `@unlink($targetFile)` in both install paths (Plugin.php:157-160, :227-230) |
| F-4 | low (KB) | **fixed by main session at step 14** (single-writer rule for `.workflow/helpers/`): DEC-006 issue numbers to be corrected and the stale re-verification sentence to be updated on merge |
| F-5 | nit | **fixed** — renamed to `testExistingBinaryIsInvalidWhenFileMissing` (unreadable case not portable, per review note) |
| F-6 | nit | **fixed** — CHANGELOG entry moved under `### Fixed`, matching the project's convention for #48/#57 |

## Round 2 (review-critical)

Verdict: **CLEAN** — F-1…F-6 re-checked with evidence (all fixed except F-4, KB, accepted-pending). New findings (test-seam fidelity only, no product impact):

| # | file:line | what is wrong | severity | status | automated check |
|---|-----------|---------------|----------|--------|-----------------|
| F-7 | tests/Composer/PluginTest.php (StubBinaryDownloader) | Stub ignored the directory passed to `createDownloader()` — with ffi loaded (always true in CI, FAQ-003) the directory test fell through to the FFI path and the stub wrote the FFI "download" into `ext-binaries/`; `downloadCalls` 2 vs 1 without ffi. Test green in both environments, meaning silently diverged. | low | open | pin `=== 1` + ffi skip, or make the stub honor the seam's directory |
| F-8 | tests/Composer/PluginTest.php (mismatch test) | Mismatch test asserted a happy end unreachable in production: the stub skipped checksum verification and overwrote with content that would be rejected by the real downloader; "unlinked" was not actually asserted (overwrite masks a missing unlink). | nit | open | downloadFailed-throwing stub + assert target absent + fallback message |

### Round 2 dispositions (main session, after fixes)

| # | severity | disposition |
|---|----------|-------------|
| F-7 | low | **fixed** — stub classes reworked: `StubBinaryDownloader` replaced by `FailingStubBinaryDownloader` rooted at the seam-provided directory; `StubDownloaderPlugin` now takes a factory closure honoring `$binaryPath` (ext path → recorded stub, FFI path → its own stub), so ffi-present/absent behave identically; call count asserted `=== 1` on the ext stub only |
| F-8 | nit | **fixed** — stubs now fail instead of overwrite: mismatch test asserts the tampered file is *absent* after install (proves the unlink; verified by mutation test — removing `@unlink` flips the suite red), plus "Extension download failed" fallback message; directory test asserts no verification warning + directory untouched + graceful failure |

## Round 2 (review-critical, commit 4833955 — verdict: CLEAN)

Re-check of F-1…F-6 with evidence (code inspection + test/probe runs):

| # | severity | round-2 status | evidence |
|---|----------|----------------|----------|
| F-1 | medium | **fixed** (caveats → F-8) | Seam `Plugin::createDownloader()` now `protected` (Plugin.php:262); offline `StubBinaryDownloader`/`StubDownloaderPlugin` + `testPackageInstallReplacesBinaryWhenExistingChecksumMismatches` (PluginTest.php:108-139) — passes, and catches the fail-open blind-accept regression (no warning + 0 calls → 2 failing assertions). Caveat: "unlinked" is not actually asserted (`file_put_contents` overwrites; all 4 assertions pass without the `@unlink`), and the stub writes content that the pinned checksum would reject in production → F-8 |
| F-2 | low | **fixed** | `is_file()` at Plugin.php:148 (ext) and :221 (FFI); directory test (PluginTest.php:141-173) passes without warnings, no unlink attempt, dir left untouched, accurate "download failed" message — confirmed by run + standalone probe |
| F-3 | low | **fixed** | Warning precedes `@unlink` in both paths (Plugin.php:159-160, :230-231); `@` suppression has precedent (PlatformDetector.php:43, ChecksumManager.php:64), PHPStan level 4 OK |
| F-4 | low (KB) | **still present in KB — accepted-pending** (main session applies at step 14) | `gh issue view` re-verified: #185 = re-verification (this PR), #182 = signature verification; decisions.md:97-100 still swapped + stale sentence. Not re-counted as open |
| F-5 | nit | **fixed** | Renamed `testExistingBinaryIsInvalidWhenFileMissing` (ChecksumManagerTest.php:210) |
| F-6 | nit | **fixed** | CHANGELOG entry under `### Fixed` (CHANGELOG.md:63), matching #48/#57, still under [Unreleased] |

New findings:

| # | file:line | what is wrong | severity | status | automated check that could catch it |
|---|-----------|---------------|----------|--------|-------------------------------------|
| F-7 | tests/Composer/PluginTest.php:274-296 (stub uses fixed `$this->dir`, ignores call-site `$binaryPath`) × Plugin.php:118-119, :172, :243 | The stub discards the directory passed to `createDownloader()`. With ext-ffi loaded (CI always has it per FAQ-003), the directory test's ext failure falls through to the FFI path, the stub "succeeds" writing the FFI binary into `ext-binaries/`, and the plugin prints "✓ FFI library downloaded successfully to: …/ffi-binaries/…" for a file that doesn't exist; `downloadCalls` is 2 (asserted ≥1) vs 1 without ffi. Empirically reproduced (this machine: 2 calls, ffi-binaries empty, stray dylib in ext-binaries). Test stays green in both environments, but the exercised flow silently diverges | low | open | assert `downloadCalls === 1` + `markTestSkipped` when `extension_loaded('ffi')`, or stub honoring the seam's directory |
| F-8 | tests/Composer/PluginTest.php:120, :128, :138 | Mismatch test doesn't prove the unlink (overwrite masks it) and the stub's "pre-verified content" contradicts the pinned checksum (real downloader would reject → unlink → fallback), so the asserted happy-end is unreachable in production; disposition overclaims "unlinked" | nit | open | stronger test: downloadFailed-throwing stub → assert target absent + fallback message + exactly-once; or stub refuses to write while target exists |

Gates: `composer lint` pass (cs-fixer 0/55, phpstan OK, rector OK, kb-lint 0); `composer test` pass — 5411 tests / 11565 assertions, exit 0, stable across reruns (8 pre-existing fgetcsv deprecations; 1 pre-existing PHPUnit deprecation in BuilderTest from #57; 1 pre-existing skip per FAQ-001). `composer.json` diff empty. No gate changes proposed.

KB candidates (single-writer rule — main session applies at step 14): DEC-006 update re-confirmed (see F-4); new FAQ-010 proposal — stubbed downloaders must honor the seam's target directory and fail (throw) rather than overwrite, so unlink + FFI-fallback paths are pinned honestly (full text in review-2.md).
