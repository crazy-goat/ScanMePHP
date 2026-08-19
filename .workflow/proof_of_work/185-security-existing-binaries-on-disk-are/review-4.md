# Review Round 4 (convergence) — issue #185 (security: existing binaries on disk are never re-verified against checksums)

**Branch:** `fix/issue-185-security-existing-binaries-on-disk-are`
**Commit:** 30ca218 (round-3 fixes, test-only for `src/`: FFI mismatch regression test + factory/docblock arity alignment; review artifacts for round 3 committed alongside)
**Reviewer:** review (round 4)
**Date:** 2026-08-19
**Mode:** read-only; only the two review output files written. Mutation probes used a throwaway copy (`/private/var/folders/.../opencode/Plugin.php.bak`) + restore, verified clean (`git diff --exit-code` empty, `git status --porcelain` empty at end).

## Overall verdict: CLEAN

No blockers, no security findings. F-9 and F-10 are **genuinely fixed** — verified by
code trace, a red mutation probe (F-9), and a green lint run (F-10). One new
low test-only finding (F-11): the FFI branch's `is_file()` guard is not
regression-pinned (its directory case has no committed test). No gate changes
proposed; none needed.

## Re-check of round-3 findings (F-1…F-10)

| # | severity | round-4 status | evidence |
|---|----------|----------------|----------|
| F-1 | medium | **fixed** | Seam `Plugin::createDownloader()` remains `protected` (Plugin.php:262); ext mismatch test (PluginTest.php:108-143) green in focused run; ext `@unlink` pinning re-established in round 3 (probe). `30ca218` touches no `src/` file (`git show 30ca218 --stat`: findings-review.md, review-3.md, PluginTest.php only). |
| F-2 | low | **fixed** (FFI mirror unpinned → F-11) | `is_file($targetFile)` at Plugin.php:148 (ext) and :221 (FFI), confirmed in current source; ext directory test (PluginTest.php:184-216) green (no verification warning, no unlink, dir untouched, graceful fallback). The FFI branch has the same guard but **no directory-at-target test** → F-11 (probe B). |
| F-3 | low | **fixed** | Warning precedes `@unlink` in both paths (Plugin.php:159-160 ext, :230-231 FFI), confirmed by source read; unchanged by 30ca218. |
| F-4 | low (KB) | **still present in KB — accepted-pending** (main session applies at step 14) | Re-read `.workflow/helpers/decisions.md:97-100` first-hand this round: DEC-006 still swaps the issue numbers ("Signature verification … is a known future enhancement (#185)"; "accepted without re-verification (#182)") and the re-verification sentence goes stale on merge. Not re-counted as open. |
| F-5 | nit | **fixed** | `testExistingBinaryIsInvalidWhenFileMissing` confirmed at tests/ChecksumManagerTest.php:210 (read this round); missing-file case covers the `@hash_file → false` branch. |
| F-6 | nit | **fixed** | `### Fixed` entry at CHANGELOG.md:63-69, under `## [Unreleased]`, confirmed via `git diff origin/main...HEAD` (only `### Fixed` section added for #185, matching #48/#57 convention). |
| F-7 | low | **fixed** | Factory (PluginTest.php:285-296) now takes both stubs and roots each at its own directory: ext path → recorded `$extDownloader` (constructed with `installPath/ext-binaries`), FFI path → `$ffiDownloader` explicitly constructed with `$binaryDir` (`ffi-binaries`). Stub still never writes (always throws) so wrong-dir writes are structurally impossible; `=== 1` pinned on the ext stub only and the FFI assertions are scoped to the FFI stub instance → ffi-present/absent behavior identical by trace; green on this ffi-loaded machine. |
| F-8 | nit | **fixed** | Failing stub + `assertFileDoesNotExist` (PluginTest.php:140) + 'Extension download failed' (:141) + `=== 1` (:142). Round-3 probe pinned the ext `@unlink`; this round's probe A pins the FFI `@unlink` (see F-9) — both unlink sites are now mutation-proven. |
| F-9 | low | **fixed** | `testPackageInstallReplacesFfiBinaryWhenExistingChecksumMismatches` (PluginTest.php:145-182): (a) **genuinely exercises the FFI mismatch branch** — plants a tampered `PlatformDetector::getBinaryName($os,$variant,$arch)` file under `ffi-binaries/` (mkdir :156, content :158); ext install fails first (offline stub), then FFI: `existingBinaryIsValid` false (pinned checksum = hash of different content) → warning 'failed SHA-256 verification. Re-downloading' (:230) → `@unlink` (:231) → FFI stub downloader invoked exactly once (:243) → throws `RuntimeException` → caught by `catch (\Exception)` → 'FFI library download failed' → pure-PHP fallback. Assertions: warning present (:178), target **absent** (:179), fallback message (:180), `downloadCalls === 1` (:181). Ran (not skipped) on this machine: focus filter 6/6; (b) **skip is correct per FAQ-003** — `extension_loaded('scanmeqr') || !extension_loaded('ffi')` → `markTestSkipped`; FAQ-003 requires FFI-dependent tests to skip (not fail) when the extension/library is missing, and `scanmeqr` preloaded short-circuits the plugin at Plugin.php:132 so the test would be vacuous; (c) **mutation probe A** (removed FFI `@unlink` at :231) → **RED**: `Failed asserting that file "…/ffi-binaries/libscanme_qr-macos-arm64.dylib" does not exist` at PluginTest.php:179; restored, tree clean. The test genuinely pins the FFI unlink + fail-closed re-download invocation. Residual caveat: the FFI branch's `is_file()` guard is not pinned → F-11. |
| F-10 | nit | **fixed** | Closure now declares all three parameters `(string $binaryPath, string $version, ChecksumManager $checksumManager)` (PluginTest.php:289), matching the invocation `($this->factory)($binaryPath, $version, $checksumManager)` (StubDownloaderPlugin::createDownloader(), :369) and the `@param \Closure(string, string, ChecksumManager): BinaryDownloader` docblock (:361). `downloadFactory` signature (:285-288) matches. PHPStan verified clean this round (`composer lint`: `[OK] No errors`, 53 files) — the `arguments.count` concern is gone. |

## New findings (commit 30ca218)

### F-11 — FFI branch's `is_file()` guard (directory-at-target) has no committed regression test | severity: low | status: open
**Where:** `src/Composer/Plugin.php:221` × `tests/Composer/PluginTest.php` (the committed directory test `testPackageInstallWithDirectoryAtTargetPathFailsCleanly`, :184-216, plants directories only under `ext-binaries/`).
**What is wrong:** The F-2 fix (`is_file()` instead of `file_exists()`) was applied to both branches, but only the ext branch got a directory-at-target regression test. The FFI branch's `is_file` guard is unpinned: **empirically (probe B)** — reverting FFI `is_file()` → `file_exists()` leaves the entire suite green, including the new FFI mismatch test (plants a *file*, where both predicates agree). Grep confirms no test plants a directory at the FFI target path (only `ffi-binaries` mentions in PluginTest.php are the new test's `mkdir` at :156 and the refuse test's emptiness glob at :51). If the guard regresses, a directory left at the FFI target path reproduces the pre-fix F-2 behavior — misleading 'failed SHA-256 verification' warning, `@unlink` failing silently, and the download failing at `fopen('wb')` with the directory never removed (repeated failure on every install). Fail-closed holds either way; impact is robustness/UX — the same class F-2 was rated low. This is the F-9 lesson (FAQ-011) one level down: the mismatch mirror is pinned, the `is_file`/directory mirror is not.
**Smallest safe fix:** mirror the ext directory test for the FFI path with the established skip guard (`extension_loaded('scanmeqr') || !extension_loaded('ffi')` → skip, FAQ-003): `mkdir` a directory named `PlatformDetector::getBinaryName($os, $variant, $arch)` under `ffi-binaries/`, run `runPackageInstall` with `downloadFactory($extStub, $ffiStub)`, assert: no 'failed SHA-256 verification' warning, 'FFI library download failed' present, `$ffiDownloader->downloadCalls === 1`, directory left untouched. Keep the ext directory test as-is.
**Automated check:** the mirrored PHPUnit test itself (target-directory-untouched + no-warning assertions fail under the `file_exists` regression).

## Commit 30ca218 scan (task items)

- **PSR-12/style** — `composer lint`: php-cs-fixer 0/55 files clean (incl. the new test and reworked factory).
- **PHPStan correctness** — `[OK] No errors` (53 files): closure arity, `$ffiDownloader ?? new FailingStubBinaryDownloader($binaryPath)` union (subclass of return type), `readonly` promoted property — all clean.
- **Environment independence** — FFI test skips without `ffi` or with `scanmeqr` preloaded (FAQ-003); Windows: the test and plugin build paths with `/` identically (`rtrim($installPath,'/') . '/' . 'ffi-binaries'`; `str_contains($binaryPath, 'ext-binaries')` matches the plugin's own joins), `mkdir 0777` + `file_put_contents` portable; `PlatformDetector::getBinaryName` used on both sides so the planted name always matches the branch's target; no network, no statics.
- **No runtime deps** — `git diff origin/main...HEAD -- composer.json` empty (FAQ-004).
- **Not order-dependent** — per-test `uniqid()` temp dirs with setUp/tearDown recursive delete; stub instances per test; the FFI test shares no state with sibling tests.
- **Scope** — test-only for `src/`; review artifacts committed alongside (established pattern in this PoW dir).

## Branch-wide sanity check (`git diff origin/main...HEAD`) — nothing missed

Full product diff re-read this round: `ChecksumManager::existingBinaryIsValid()` (legacy accept when no checksum pinned — regression-pinned by `testKeepsBinaryWhenNoChecksumConfigured` and ChecksumManagerTest; fail-closed `@hash_file` otherwise), `Plugin` ext + FFI branches (is_file → verify → warning → unlink → verified re-download; downloader seam protected), CHANGELOG `### Fixed` #185 entry, `composer.json` untouched. `PluginTest` full file read: 6 tests, all offline, no FAQ-007 message-comparison additions (stub throws `RuntimeException`, never enters the `checksumMissing` message-equality branch; the new assertions compare user-visible output). `ChecksumManagerTest` region re-read (F-5). No stray artifacts, no leftover probes. The only gap found by this pass is F-11.

## Gates

| Command | Result |
|---------|--------|
| `composer lint` | **PASS** — cs-fixer 0/55, PHPStan `[OK] No errors` (53 files), Rector OK, kb-lint 0 warnings |
| `composer test` (full) | **PASS** — 5412 tests / 11569 assertions, exit 0 (exactly +1 test / +4 assertions vs round 3 = the new FFI test); deprecations identical to rounds 2-3 pre-existing set (8 fgetcsv + 1 PHPUnit deprecation in BuilderTest, from #57); 1 skip (FAQ-001) |
| `vendor/bin/phpunit tests/Composer/PluginTest.php` | **OK** — 6 tests / 26 assertions, on PHP 8.5.9 with ffi loaded; the new FFI test ran (not skipped) |
| Mutation probe A (read-only) | FFI `@unlink` (Plugin.php:231) commented out → `testPackageInstallReplacesFfiBinaryWhenExistingChecksumMismatches` **RED** at PluginTest.php:179 ('the mismatched FFI library must be unlinked before the re-download'); restored from throwaway copy |
| Mutation probe B (read-only) | FFI `is_file()` → `file_exists()` (Plugin.php:221) → FFI mismatch test **stays GREEN** (1 test, 4 assertions) — the is_file guard on the FFI branch is unpinned → F-11; restored |
| `git status --porcelain` / `git diff --exit-code` | clean after all probes (only the two review files written by this round) |
| `composer.json` diff vs origin/main | empty (zero deps, FAQ-004) |
| `decisions.md:97-100` read | DEC-006 issue numbers still swapped + stale sentence (F-4, accepted-pending) |

## Candidate knowledge-base entries (proposed; single-writer rule — main session decides at step 14)

1. **Update DEC-006** (F-4, re-confirmed first-hand this round): swap the issue numbers (#185 = on-disk re-verification — implemented fail-closed by this PR; #182 = signature verification — open) and drop the stale "Existing on-disk binaries are currently accepted without re-verification (#182)" sentence on merge.
2. **FAQ-010 — "Stubbed downloaders in Composer-plugin tests: honor the seam's directory and fail, don't overwrite"** (round-2/3 proposal, validated by the committed fix): *tags* tests composer security; *trigger* writing a stub for `BinaryDownloader` / overriding `createDownloader()`; *body* — success-writing stubs mask a missing unlink (overwrite); stubs must throw + tests assert target **absent** + fallback message + exactly-once per instance (mutation-proven twice now, ext and FFI); root each stub at the seam-provided directory because the FFI fallback reuses the same seam with a different directory; pin exact call counts so ffi-present/absent environments assert identically.
3. **FAQ-011 — "A regression test on one mirror branch does not pin its sibling"** (round-3 proposal, now validated **twice**): *tags* tests composer security ffi; *trigger* fixing a security/robustness property implemented in two mirrored branches (ext + FFI fallback in `src/Composer/Plugin.php`); *body* — round 3: the ext mismatch test did not pin the FFI mismatch flow (F-9 → fixed by a planted FFI test with an `extension_loaded('ffi')` skip per FAQ-003); round 4: the ext directory/is_file test does not pin the FFI branch's `is_file()` guard either (F-11 — a `file_exists` regression there merges green). Each mirrored branch needs its own planted test — tampered *file* for the mismatch flow, planted *directory* for the is_file guard.
4. Tag-index note for the main session: no FAQ/DEC entry currently carries a `tests` tag; entries 2 and 3 above should include it so the index routes future test-writing sessions to them.

## Commands run

`composer lint`; `composer test` (full); `vendor/bin/phpunit tests/Composer/PluginTest.php` (focused, 6/6); mutation probes A/B (copy → python3 edit → filtered run → restore → `git diff --exit-code` + `git status --porcelain`); `git show 30ca218` (stat + PluginTest diff); `git diff origin/main...HEAD` (stat + full src/CHANGELOG/composer diff); reads of `src/Composer/Plugin.php`, `src/ChecksumManager.php`, `tests/Composer/PluginTest.php`, `tests/ChecksumManagerTest.php:195-234`, `.workflow/helpers/*` (tag index + matching entries), `.workflow/proof_of_work/185-*/{findings-review.md, review-3.md}`. Repo left pristine: the only writes are this file and the `findings-review.md` Round 4 append.
