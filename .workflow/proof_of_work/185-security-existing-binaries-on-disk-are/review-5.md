# Review Round 5 (convergence / final gate) — issue #185 (security: existing binaries on disk are never re-verified against checksums)

**Branch:** `fix/issue-185-security-existing-binaries-on-disk-are`
**Commit:** 39a8b13 (round-4 fix, test-only for `src/`: FFI directory-at-target regression test pinning the FFI `is_file()` guard, plus review artifacts)
**Reviewer:** review (round 5)
**Date:** 2026-08-19
**Mode:** read-only; only the two review output files written. Mutation probe used a throwaway copy (`/private/var/folders/.../opencode/Plugin.php.review5.bak`) + restore; `git diff --exit-code` and `git status --porcelain` verified empty at end.

## Overall verdict: CLEAN

No blockers, no security findings, no new findings in `39a8b13` or the branch as a
whole. F-11 is **genuinely fixed** — verified by code trace and a **red mutation
probe** this round (FFI `is_file()` → `file_exists()` turns the new test red).
F-1…F-10 re-confirmed fixed (F-4 KB item still accepted-pending for workflow
step 14 — must land before/at merge). This is the last gate before the PR.

## Re-check of all findings F-1…F-11

| # | severity | round-5 status | evidence |
|---|----------|----------------|----------|
| F-1 | medium | **fixed** | Seam `Plugin::createDownloader()` remains `protected` (Plugin.php:262); ext mismatch test (PluginTest.php:108-143) green in focused run (7 tests / 30 assertions, all pass). `39a8b13` touches no `src/` file. |
| F-2 | low | **fixed** (+ FFI mirror now pinned → F-11 fixed) | `is_file($targetFile)` confirmed at Plugin.php:148 (ext) and :221 (FFI) — re-grep after the probe restore; ext directory test (PluginTest.php:224-256) green. The FFI guard is now regression-pinned by the new test (see F-11). |
| F-3 | low | **fixed** | Warning precedes `@unlink` in both paths (Plugin.php:159-160 ext, :230-231 FFI), confirmed by full source read this round; unchanged by `39a8b13`. |
| F-4 | low (KB) | **still present in KB — accepted-pending** (main session applies at step 14) | Read `decisions.md:96-100` first-hand again: DEC-006 still swaps the issue numbers ("Signature verification … (#185)" should be #182; "accepted without re-verification (#182)" should be #185) and the re-verification sentence goes stale on merge. **Final-gate reminder: this must be applied at step 14 before the PR merges.** Not re-counted as open. |
| F-5 | nit | **fixed** | `testExistingBinaryIsInvalidWhenFileMissing` present in the branch diff of tests/ChecksumManagerTest.php (renamed from `…OrUnreadable`); missing-file case covers the `@hash_file → false` branch. |
| F-6 | nit | **fixed** | `### Fixed` entry at CHANGELOG.md:63-69 under `## [Unreleased]` (branch diff re-read this round); content accurate: on-disk binary with mismatching pinned hash is removed and re-downloaded via the verified fail-closed path; no claim of signature verification. |
| F-7 | low | **fixed** | Factory (PluginTest.php:325-336) routes `ext-binaries` → `$extDownloader`, everything else → `$ffiDownloader`/fresh failing stub rooted at the passed directory; stub never writes (always throws), so wrong-dir writes are structurally impossible; exact `=== 1` counts per instance; green on this ffi-loaded machine (focused run). |
| F-8 | nit | **fixed** | Failing stub + `assertFileDoesNotExist` (PluginTest.php:140, :179) + fallback messages + exactly-once; unlink sites mutation-proven in rounds 3 (ext) and 4 (FFI). |
| F-9 | low | **fixed** | `testPackageInstallReplacesFfiBinaryWhenExistingChecksumMismatches` (PluginTest.php:145-182) ran (not skipped) and passed in this round's focused run; FAQ-003 skip guard confirmed by read; round-4 probe A already proved the FFI `@unlink` pin. |
| F-10 | nit | **fixed** | Factory closure declares all three parameters `(string $binaryPath, string $version, ChecksumManager $checksumManager)` (PluginTest.php:329), matching invocation (StubDownloaderPlugin::createDownloader(), :409) and docblock (:401); `composer lint` PHPStan `[OK] No errors` (53 files) this round. |
| F-11 | low | **fixed** | New test `testPackageInstallWithDirectoryAtFfiTargetPathFailsCleanly` (PluginTest.php:184-222): plants a **directory** named `PlatformDetector::getBinaryName($os, $variant, $arch)` under `ffi-binaries/` (mirroring the ext directory test), asserts no 'failed SHA-256 verification' warning (:218), 'FFI library download failed' (:219), `$ffiDownloader->downloadCalls === 1` (:220), directory left untouched (:221). Skip guard per FAQ-003 (`scanmeqr` preloaded or `!ffi` → skip). **Ran (not skipped) this round** on PHP 8.5.9 + ffi. **Mutation probe (this round):** FFI `is_file()` at Plugin.php:221 regressed to `file_exists()` on a throwaway copy → new test **RED** at PluginTest.php:218 — output contains '⚠️  Existing FFI library failed SHA-256 verification. Re-downloading the verified library.' (the exact pre-fix F-2 failure mode), 2 tests / 1 failure. Restored from backup; `git diff --exit-code` empty, `git status --porcelain` empty; test green again post-restore. The FFI `is_file` guard is genuinely pinned. |

## Commit 39a8b13 scan

- **Scope** — test-only for `src/`; review artifacts (findings-review.md round-4 append, review-4.md) committed alongside, matching the established pattern in this PoW dir.
- **PSR-12 / PHPStan / Rector** — clean via `composer lint` (cs-fixer 0/55, PHPStan OK 53 files, Rector OK, kb-lint 0).
- **Test quality** — mirrors the ext sibling's assertions exactly; discriminating assertion is the `assertStringNotContainsString('failed SHA-256 verification')` — proven sensitive by the probe; `=== 1` on the FFI stub only; `assertDirectoryExists` proves no removal attempt; planted name derives from `PlatformDetector` exactly as the plugin builds it; per-test `uniqid()` temp dir with recursive cleanup; no network, no statics; order-independent.
- **Environment independence** — skips without `ffi` or with `scanmeqr` preloaded (FAQ-003); paths joined with `/` exactly as the plugin does (`rtrim(..., '/') . '/'`), portable on Windows; no platform-specific naming assumptions (uses `getBinaryName`).
- **No new issues found** — full `git show 39a8b13` read; the diff is exactly the one test + docs, no stray edits.

## Branch-wide holistic sweep (`git diff origin/main...HEAD`) — final gate

- Product diff = `ChecksumManager::existingBinaryIsValid()` (+14: legacy accept only when no checksum pinned, fail-closed `@hash_file` otherwise), `Plugin` ext + FFI branches (`is_file` → verify → warning→ `@unlink` → verified re-download; seam `createDownloader()` protected), CHANGELOG `### Fixed` entry, tests. No other files changed; `composer.json` diff **empty** (zero runtime deps, FAQ-004).
- Fail-closed path re-verified: `BinaryDownloader::download()` unchanged on this branch — `checksumMissing` thrown before any HTTP (BinaryDownloader.php:49), post-download `hash_file` mismatch → `checksumMismatch` (:91-94).
- No FAQ-007 message-comparison additions; the new test asserts user-visible output, and the stub throws `RuntimeException` (never enters the existing `checksumMissing` string-equality catch, which stays tracked by #184).
- No debug artifacts / TODO / FIXME / var_dump in the branch diff (grep sweep clean).
- Docs: nothing in README/docs/examples claims the old always-accept behavior, so nothing is made stale by #185 (README never documented `extra.scanmephp.checksums` — pre-existing, out of scope). No docs update required.
- Acknowledged out-of-scope items (re-confirmed, not re-counted): `InstallScript` legacy accept path is unreferenced dead code (composer.json `extra.class` → `Composer\Plugin`; grep finds no other reference) — coder Finding 3; `curl_close` PHP 8.5 deprecation — coder Finding 1; naming duplication — coder Finding 2; message-equality catches — #184; `FfiEncoder` post-install trust — #65/#182.
- Commit history: 5 conventional commits, `closes #185` on the implementation commit `f30f337` (DEC-002 compliant). Proof-of-work trail complete (code-decision, findings-coder, review-1…5, findings-review rounds 1-5).

## Gates

| Command | Result |
|---------|--------|
| `composer lint` | **PASS** — cs-fixer 0/55, PHPStan `[OK] No errors` (53 files), Rector OK, kb-lint 0 warnings |
| `composer test` (full) | **PASS** — **5413 tests / 11573 assertions**, exit 0 (exactly +1 test / +4 assertions vs round 4 = the new FFI directory test); deprecations identical to the pre-existing set (8 fgetcsv + 1 PHPUnit deprecation in BuilderTest, from #57); 1 skip per FAQ-001 |
| `vendor/bin/phpunit tests/Composer/PluginTest.php` | **OK** — **7 tests / 30 assertions** on PHP 8.5.9 with ffi loaded; both FFI tests ran (not skipped), including the new directory test |
| Mutation probe (read-only) | FFI `is_file()` → `file_exists()` (Plugin.php:221) → `testPackageInstallWithDirectoryAtFfiTargetPathFailsCleanly` **RED** at PluginTest.php:218 ('failed SHA-256 verification' present in output); restored from throwaway copy; re-run green |
| `git status --porcelain` / `git diff --exit-code` | clean after the probe — the only writes are this file and the `findings-review.md` Round 5 append |
| `composer.json` diff vs origin/main | empty (zero deps, FAQ-004) |
| `decisions.md:96-100` read | DEC-006 issue numbers still swapped + stale sentence (F-4, accepted-pending — apply at step 14 before merge) |

## Candidate knowledge-base entries (proposed; single-writer rule — main session decides at step 14)

1. **Update DEC-006** (F-4, re-confirmed first-hand, now with end-of-cycle urgency): swap the issue numbers (#185 = on-disk re-verification — implemented fail-closed by this PR; #182 = signature verification — open) and drop the stale "Existing on-disk binaries are currently accepted without re-verification (#182)" sentence. Apply at step 14, before the PR merges.
2. **FAQ-010 — "Stubbed downloaders in Composer-plugin tests: honor the seam's directory and fail, don't overwrite"** (rounds 2-4 proposal, validated by the committed fix): *tags* tests composer security; *trigger* writing a stub for `BinaryDownloader` / overriding `createDownloader()`; body — success-writing stubs mask a missing unlink (overwrite); stubs must throw + tests assert target **absent** + fallback message + exactly-once per instance (mutation-proven on both ext and FFI unlinks); root each stub at the seam-provided directory; pin exact call counts so ffi-present/absent environments assert identically.
3. **FAQ-011 — "A regression test on one mirror branch does not pin its sibling"** (rounds 3-4 proposal, now validated **three times**): *tags* tests composer security ffi; *trigger* fixing a security/robustness property implemented in two mirrored branches (ext + FFI fallback in `src/Composer/Plugin.php`); body — F-9: ext mismatch test didn't pin the FFI mismatch flow (fixed by a planted-file FFI test with the FAQ-003 skip); F-11: ext directory/`is_file` test didn't pin the FFI `is_file` guard (fixed by a planted-*directory* FFI test); round 5 confirms F-11's fix is real: regressing the FFI guard to `file_exists()` flips only the new test red. Each mirrored branch needs its own planted test — tampered *file* for the mismatch flow, planted *directory* for the `is_file` guard.
4. **Tag-index note**: no FAQ/DEC entry carries a `tests` tag yet; entries 2 and 3 above should include it so the index routes future test-writing sessions to them.

## Commands run

`composer lint`; `composer test` (full); `vendor/bin/phpunit tests/Composer/PluginTest.php` (focused, 7/7); `vendor/bin/phpunit --filter '...DirectoryAtFfiTargetPath...|...ReplacesFfiBinary...'` under mutation (2 tests / 1 failure as expected); mutation probe (copy → python3 single-line edit of :221 → filtered run → restore from backup → `git diff --exit-code` + `git status --porcelain` + re-run green); `git show 39a8b13`; `git diff origin/main...HEAD` (stat, full src/CHANGELOG/tests diffs, numstat); reads of `src/Composer/Plugin.php` (full), `src/ChecksumManager.php` (full), `src/BinaryDownloader.php` (fail-closed lines), `tests/Composer/PluginTest.php` (full), `tests/ChecksumManagerTest.php` (branch diff), `README.md` (plugin/docs sections), `.workflow/helpers/*` (tag index + matching entries FAQ-001/002/006/007/008, DEC-006), `.workflow/proof_of_work/185-*/{findings-review.md, findings-coder.md, review-4.md}`. Repo left pristine except the two review output files.
