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

## Round 3 (review)

Verdict: **CLEAN** — F-1…F-8 re-checked with evidence (all fixed except F-4, KB, accepted-pending). New findings:

| # | file:line | what is wrong | severity | status | automated check |
|---|-----------|---------------|----------|--------|-----------------|
| F-9 | src/Composer/Plugin.php:221-232 (FFI mirror branch) | The FFI-path re-verification + unlink branch has no committed regression test — a regression there (file_exists reintroduced, unlink dropped) would merge green. The ext mirror is pinned; the FFI fallback — the issue's exact scenario — is not. | low | open | mirror ext mismatch test for the FFI path with `extension_loaded('ffi')` skip (FAQ-003) |
| F-10 | tests/Composer/PluginTest.php (StubDownloaderPlugin docblock) | Docblock declared `\Closure(string, string, ChecksumManager)` while the closure took 1 arg; PHP silently drops extras. | nit | open | PHPStan arguments.count (it does flag it — see disposition) |

### Round 3 dispositions (main session, after fixes)

| # | severity | disposition |
|---|----------|-------------|
| F-9 | low | **fixed** — added `testPackageInstallReplacesFfiBinaryWhenExistingChecksumMismatches` (PlantDefinedSkip: skips without `ffi` per FAQ-003, or with scanmeqr preloaded): tampered FFI binary under `ffi-binaries/` → warning → unlink → FFI downloader invoked exactly once → file absent, "FFI library download failed" message. Live-verified by mutation: commenting out the FFI `@unlink` (Plugin.php:231) flips the test red |
| F-10 | nit | **fixed** — closure now declares all three parameters `(string $binaryPath, string $version, ChecksumManager $checksumManager)`, matching the invocation in `StubDownloaderPlugin::createDownloader()` and the corrected docblock; PHPStan `arguments.count` (which flagged the original) is now clean |

## Round 4 (review)

Verdict: **CLEAN** — F-1…F-10 re-checked with evidence (F-9 and F-10 fixed by `30ca218`; all others still fixed except F-4, KB, accepted-pending). New finding:

| # | file:line | what is wrong | severity | status | automated check |
|---|-----------|---------------|----------|--------|-----------------|
| F-11 | src/Composer/Plugin.php:221 (FFI `is_file()` guard) | The FFI branch's `is_file()` guard has no directory-at-target regression test; the ext-only directory test does not pin it. Empirically proven (probe B): regressing the FFI `is_file()` to `file_exists()` leaves the whole suite green — the F-2 failure mode (misleading warning, silent unlink failure, directory never removed) would come back unnoticed on the FFI path. | low | open | mirror the ext directory test for the FFI path with the FAQ-003 skip |

### Round 4 dispositions (main session, after fixes)

| # | severity | disposition |
|---|----------|-------------|
| F-11 | low | **fixed** — added `testPackageInstallWithDirectoryAtFfiTargetPathFailsCleanly` (skip without `ffi` per FAQ-003): directory at FFI target path → no verification warning, directory untouched, FFI downloader invoked exactly once, "FFI library download failed" fallback. Live-verified by mutation: regressing FFI `is_file()` → `file_exists()` flips the test red |

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

## Round 3 (review-critical, commit a34ab7e — verdict: CLEAN)

Re-check of F-1…F-8 with evidence (code inspection + runs + read-only mutation probe):

| # | severity | round-3 status | evidence |
|---|----------|----------------|----------|
| F-1 | medium | **fixed** | Seam `Plugin::createDownloader()` protected (Plugin.php:262); mismatch test (PluginTest.php:108-143) passes offline and still catches fail-open regression; round-2 caveats resolved via F-8 (unlink now proven — see F-8 probe). |
| F-2 | low | **fixed** | `is_file()` at Plugin.php:148 and :221; directory test (PluginTest.php:145-177) passes without warnings, no unlink, dir untouched, graceful fallback. |
| F-3 | low | **fixed** | Warning precedes `@unlink` in both paths (Plugin.php:159-160, :230-231); `@` precedent in-tree; PHPStan level 4 OK. |
| F-4 | low (KB) | **still present in KB — accepted-pending** (step 14) | First-hand `gh issue view`: #185 = on-disk re-verification (this PR), #182 = signature verification; decisions.md:97-100 still swapped + stale sentence. Not re-counted as open. |
| F-5 | nit | **fixed** | `testExistingBinaryIsInvalidWhenFileMissing` confirmed (ChecksumManagerTest.php:210). |
| F-6 | nit | **fixed** | `### Fixed` at CHANGELOG.md:63, under [Unreleased]; a34ab7e didn't touch CHANGELOG. |
| F-7 | low | **fixed** | Factory (PluginTest.php:246-255) roots ext stub at `ext-binaries`, FFI gets a fresh failing stub rooted at `ffi-binaries`; stub never writes (always throws) so wrong-dir writes are structurally impossible; `=== 1` pinned on ext stub only → ffi-present/absent identical (no assertion touches FFI output); green on this ffi-loaded machine. |
| F-8 | nit | **fixed** | Failing stub + `assertFileDoesNotExist` (PluginTest.php:140) + 'Extension download failed' (:141) + `=== 1` (:142). **Mutation probe**: commented out `@unlink` at Plugin.php:160 → test RED ("Failed asserting that file … does not exist", PluginTest.php:140); restored from throwaway copy; `git diff --exit-code` and `git status --porcelain` clean afterwards. The test genuinely pins the unlink. |

New findings:

| # | file:line | what is wrong | severity | status | automated check that could catch it |
|---|-----------|---------------|----------|--------|-------------------------------------|
| F-9 | src/Composer/Plugin.php:221-232 (FFI re-verification + `@unlink` branch) — no plant/test under `ffi-binaries/` in tests/Composer/PluginTest.php | The FFI mirror of the ext mismatch flow has **no committed regression test**: nothing plants a tampered FFI binary, so a regression there (e.g. `is_file()` → `file_exists()`, dropped re-verification, removed `@unlink` at :231) merges green. Ext path is pinned; FFI fallback (Plugin.php:118-119) — the exact scenario of this issue — is not. Coder's throwaway smoke test once covered it and was deleted (findings-coder.md:22-25). No product bug; test coverage gap on a mirrored security-relevant branch. | low | open | PHPUnit test mirroring the ext mismatch test: plant tampered `PlatformDetector::getBinaryName(...)` under `ffi-binaries/`, assert warning + target absent + 'FFI library download failed' + exactly-once on an FFI-rooted failing stub; skip when `!extension_loaded('ffi')` (FAQ-003) |
| F-10 | tests/Composer/PluginTest.php:320 (@param `\Closure(string, string, ChecksumManager): BinaryDownloader`) vs :248 (closure declares only `(string $binaryPath)`) | Docblock overstates the factory closure's arity; relies on PHP silently discarding `$version`/`$checksumManager` (passed at :328). Works today; PHPStan level 4 doesn't cross-check (plain `\Closure` return). Doc-accuracy only. | nit | open | none reasonable at level 4 (docblock-vs-declaration callable arity isn't enforced) |

Gates: `composer lint` pass (cs-fixer 0/55, PHPStan OK, rector OK, kb-lint 0); `composer test` pass ×2 — 5411 tests / 11565 assertions, exit 0 both runs, deprecations identical to round 2's pre-existing set; PluginTest focused 5 tests / 22 assertions OK on PHP 8.5.9 with ffi loaded; composer.json diff empty; working tree pristine after the mutation probe. No gate changes proposed.

KB candidates (single-writer rule — main session applies at step 14): DEC-006 update re-confirmed (F-4); FAQ-010 proposal validated by the committed fix (full text in review-3.md); new FAQ-011 proposal — a regression test on one mirror branch (ext) does not pin its sibling (FFI fallback); give each mirrored branch its own planted test with an `extension_loaded('ffi')` skip.

## Round 4 (review, commit 30ca218 — verdict: CLEAN)

Re-check of F-1…F-10 with evidence (code inspection + runs + read-only mutation probes A/B with throwaway copy and restore):

| # | severity | round-4 status | evidence |
|---|----------|----------------|----------|
| F-1 | medium | **fixed** | Seam `Plugin::createDownloader()` protected (Plugin.php:262); ext mismatch test (PluginTest.php:108-143) green; `30ca218` touches no `src/` file. |
| F-2 | low | **fixed** (FFI mirror unpinned → F-11) | `is_file()` at Plugin.php:148 (ext) and :221 (FFI); ext directory test (PluginTest.php:184-216) green — no warning, no unlink, dir untouched, graceful fallback. FFI branch guard has no directory test → F-11. |
| F-3 | low | **fixed** | Warning precedes `@unlink` in both paths (Plugin.php:159-160, :230-231); confirmed by source read. |
| F-4 | low (KB) | **still present in KB — accepted-pending** (main session applies at step 14) | Re-read `decisions.md:97-100` this round: DEC-006 still swaps #185/#182 and the "accepted without re-verification (#182)" sentence goes stale on merge. Not re-counted as open. |
| F-5 | nit | **fixed** | `testExistingBinaryIsInvalidWhenFileMissing` confirmed (ChecksumManagerTest.php:210). |
| F-6 | nit | **fixed** | `### Fixed` at CHANGELOG.md:63-69 under [Unreleased], confirmed via `git diff origin/main...HEAD`. |
| F-7 | low | **fixed** | Factory (PluginTest.php:285-296) takes both stubs, roots each at its own directory; stub always throws (wrong-dir writes structurally impossible); `=== 1` scoped per instance; green on this ffi-loaded machine. |
| F-8 | nit | **fixed** | Failing stub + `assertFileDoesNotExist` (:140) + fallback message (:141) + `=== 1` (:142). **Probe A this round** pins the FFI `@unlink` the same way the round-3 probe pinned the ext one — both unlink sites are mutation-proven. |
| F-9 | low | **fixed** | `testPackageInstallReplacesFfiBinaryWhenExistingChecksumMismatches` (PluginTest.php:145-182) genuinely exercises the FFI mismatch branch (tampered file under `ffi-binaries/` → warning → unlink → FFI stub invoked exactly once → target absent → 'FFI library download failed'); skip correct per FAQ-003 (`scanmeqr` preloaded or `!ffi` → skip); ran (not skipped) on this machine. **Probe A** (FFI `@unlink` commented out) → **RED** at PluginTest.php:179; restored, tree clean. The unlink + fail-closed re-download invocation are genuinely pinned. Caveat: the FFI branch's `is_file` guard stays unpinned → F-11. |
| F-10 | nit | **fixed** | Closure declares all three params `(string $binaryPath, string $version, ChecksumManager $checksumManager)` (PluginTest.php:289) matching the invocation (:369) and docblock (:361); `composer lint` PHPStan `[OK] No errors` (53 files). |

New findings:

| # | file:line | what is wrong | severity | status | automated check that could catch it |
|---|-----------|---------------|----------|--------|-------------------------------------|
| F-11 | src/Composer/Plugin.php:221 × tests/Composer/PluginTest.php (ext-only directory test) | The FFI branch's `is_file()` guard has no directory-at-target regression test. Empirically (probe B this round): reverting FFI `is_file()` → `file_exists()` leaves the whole suite green — the new FFI mismatch test plants a *file*, where both predicates agree; no committed test plants a directory at the FFI target path. If the guard regresses, a directory at the FFI target reproduces the pre-fix F-2 behavior (misleading 'failed SHA-256 verification' warning, silent unlink failure, download failing at `fopen('wb')`, directory never removed → repeated failure per install). Fail-closed holds; robustness/UX class, same as F-2/F-9. | low | open | mirror the ext directory test for the FFI path: plant `PlatformDetector::getBinaryName(...)` as a *directory* under `ffi-binaries/`, assert no verification warning + 'FFI library download failed' + `downloadCalls === 1` + directory untouched, with the FAQ-003 skip (`scanmeqr` preloaded or `!ffi`) |

Gates: `composer lint` pass (cs-fixer 0/55, PHPStan OK, rector OK, kb-lint 0); `composer test` pass — **5412 tests / 11569 assertions** (exactly +1 test / +4 assertions = the new FFI test), exit 0, deprecations identical to the pre-existing rounds-2/3 set (8 fgetcsv + 1 PHPUnit in BuilderTest from #57; 1 skip per FAQ-001); focused PluginTest 6 tests / 26 assertions OK on PHP 8.5.9 with ffi loaded; probes A (FFI unlink removed → RED at :179) and B (FFI is_file→file_exists → GREEN → F-11) fully rolled back — `git diff --exit-code` and `git status --porcelain` clean; composer.json diff empty; no gate changes proposed.

KB candidates (single-writer rule — main session applies at step 14): DEC-006 update re-confirmed (F-4); FAQ-010 (stub discipline: throw don't overwrite, honor the seam's directory, per-instance call counts) validated by the committed fix; FAQ-011 (mirrored branches each need their own planted test) validated **twice** — F-9's planted-file fix landed as proposed, and F-11 shows the same lesson for the planted-*directory* (is_file) mirror. Full texts in review-4.md. Tag-index note: no FAQ/DEC entry carries a `tests` tag yet — FAQ-010/011 should add it.
