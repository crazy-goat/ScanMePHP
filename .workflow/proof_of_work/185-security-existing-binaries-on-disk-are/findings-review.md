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
