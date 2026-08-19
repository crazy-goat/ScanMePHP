# Review Round 3 — issue #185 (security: existing binaries on disk are never re-verified against checksums)

**Branch:** `fix/issue-185-security-existing-binaries-on-disk-are`
**Commit:** a34ab7e (round-2 fixes, test-only for `src/`: `tests/Composer/PluginTest.php` reworked; plus round-2 review artifacts committed)
**Reviewer:** review-critical (round 3)
**Date:** 2026-08-19
**Mode:** read-only; only the two review output files written. Mutation probe used a throwaway copy + restore, verified clean (`git diff --exit-code`, `git status --porcelain` both empty at end).

## Overall verdict: CLEAN

No blockers, no security findings. F-1…F-6 re-verified still fixed (F-4 KB,
accepted-pending). **F-7 and F-8 both genuinely fixed** — the rework in
`a34ab7e` does what its dispositions claim:
- the factory roots each stub in its own directory (F-7), and
- the mismatch test provably fails without the unlink (F-8) — verified by a
  mutation probe that flipped the suite red and was then fully rolled back.

Two new findings, both test-only, no product or security impact:
**F-9 (low)** — the FFI-path re-verification/unlink orchestration
(Plugin.php:221-232) has no committed regression test (the ext mirror does);
**F-10 (nit)** — `StubDownloaderPlugin`'s `@param` docblock overstates the
factory closure's arity. No gate changes proposed; none needed.

## Re-check of round-2 findings (F-1…F-8)

| # | severity | round-3 status | evidence |
|---|----------|----------------|----------|
| F-1 | medium | **fixed** | Seam `Plugin::createDownloader()` protected (Plugin.php:262); `testPackageInstallReplacesBinaryWhenExistingChecksumMismatches` (PluginTest.php:108-143) passes offline and still catches the fail-open blind-accept regression (no warning + 0 stub calls → 2+ failing assertions). Round-2 caveats (unlink not proven, unreachable happy-end) are resolved by the F-8 fix — see F-8 row + mutation probe. |
| F-2 | low | **fixed** | `is_file($targetFile)` at Plugin.php:148 (ext) and :221 (FFI), both confirmed in current source. `testPackageInstallWithDirectoryAtTargetPathFailsCleanly` (PluginTest.php:145-177) passes: no verification warning, no unlink attempt, directory left untouched, graceful "download failed" fallback. |
| F-3 | low | **fixed** | Warning precedes `@unlink` in both paths: Plugin.php:159-160 (ext), :230-231 (FFI). `@` suppression has in-tree precedent (PlatformDetector.php:43, ChecksumManager.php:64); PHPStan level 4 passes. |
| F-4 | low (KB) | **still present in KB — accepted-pending** (main session applies at step 14) | First-hand re-verification via `gh issue view`: **#185** = "security: existing binaries on disk are never re-verified against checksums" (this PR, OPEN); **#182** = "security: add signature verification (minisign/cosign/GPG) for release assets" (OPEN). `.workflow/helpers/decisions.md:97-100` (DEC-006) still swaps the two numbers and the "accepted without re-verification (#182)" sentence goes stale on merge. Unchanged since rounds 1-2; not re-counted as open. |
| F-5 | nit | **fixed** | `testExistingBinaryIsInvalidWhenFileMissing` confirmed at tests/ChecksumManagerTest.php:210; missing-file case covers the same `@hash_file → false` branch (unreadable case still untested on purpose — not portable). |
| F-6 | nit | **fixed** | #185 entry under `### Fixed` (CHANGELOG.md:63), matching the #48/#57 convention; still under `## [Unreleased]`. `a34ab7e` touched no CHANGELOG lines. |
| F-7 | low | **fixed** | Factory closure (PluginTest.php:246-255) roots each stub at the seam-provided directory: ext path → the recorded `$extDownloader` (constructed with `$binaryDir` = `installPath/ext-binaries`, matching Plugin.php:141); FFI path → a **fresh** `FailingStubBinaryDownloader($binaryPath)` rooted at the FFI dir (Plugin.php:214/243). The stub never writes — it always throws — so no file can land in the wrong directory by construction. Call count pinned `=== 1` on the ext stub only (PluginTest.php:142, :175), which is provably ffi-present/absent identical: the FFI branch's output is asserted nowhere; the FFI stub is a separate instance that never touches `$extDownloader->downloadCalls`. Green on this machine with ffi loaded; trace-identical without ffi. |
| F-8 | nit | **fixed** | Mismatch test now: failing stub (throws, never writes) → `assertFileDoesNotExist($binaryPath)` (PluginTest.php:140) proves the unlink, plus "Extension download failed" fallback message (:141) and exactly-once on the ext stub (:142). **Mutation probe performed:** threw-away copy of `src/Composer/Plugin.php`, commented out `@unlink($targetFile);` at line 160, ran `--filter testPackageInstallReplacesBinaryWhenExistingChecksumMismatches` → **RED**: `Failed asserting that file "…/ext-binaries/php-ext-macos-arm64-php85.so" does not exist` at PluginTest.php:140 (the `the mismatched binary must be unlinked before the re-download` message). File restored from copy; `git diff --exit-code` empty; `git status --porcelain` empty. The committed test therefore genuinely pins the unlink. |

## New findings (commit a34ab7e)

### F-9 — FFI-path re-verification + unlink orchestration has no committed regression test | severity: low | status: open
**Where:** `src/Composer/Plugin.php:221-232` (FFI `is_file` → `existingBinaryIsValid` → warning → `@unlink` → re-download), mirrored in the ext path at :148-161; the committed test coverage in tests/Composer/PluginTest.php covers **only the ext path**.
**What is wrong:** The mismatch regression test (`:108-143`) and directory test (`:145-177`) plant files/directories exclusively under `ext-binaries/`. No test plants anything in `ffi-binaries/` (grep: only PluginTest.php:51 globs the FFI dir, as an emptiness side effect of the refuse test). The FFI branch — which is exactly where this issue's scenario plays out when the ext install fails (the fallback, Plugin.php:118-119) — can regress without the suite noticing: e.g. `is_file()` → `file_exists()`, a dropped re-verification, or a removed `@unlink` at :231 would all merge green. The coder verified the FFI path once with a throwaway network smoke test and deleted it (findings-coder.md:22-25); nothing committed pins it.
**Why it is not a blocker:** the ext path pins the identical logic and the shared decision core (`ChecksumManager::existingBinaryIsValid`, 4 cases in ChecksumManagerTest); product code is correct; this is a regression-test gap on a mirrored branch, the same class round-1 F-1 closed for the ext path.
**Smallest safe fix:** mirror the ext mismatch test: plant a tampered `PlatformDetector::getBinaryName($os, $variant, $arch)` file under `ffi-binaries/`, run `runPackageInstall` with the `downloadFactory` stub, assert 'failed SHA-256 verification. Re-downloading' + target **absent** + 'FFI library download failed' + exactly-once on an FFI-rooted failing stub; `markTestSkipped` when `!extension_loaded('ffi')` (FAQ-003 pattern — the FFI install path returns early at Plugin.php:206-210 without ffi).
**Automated check:** the PHPUnit test itself (same self-verifying shape as F-8: target-absent assertion fails without the FFI `@unlink`).

### F-10 — `StubDownloaderPlugin` docblock overstates the factory closure's arity | severity: nit | status: open
**Where:** tests/Composer/PluginTest.php:320 (`@param \Closure(string, string, ChecksumManager): BinaryDownloader $factory`) vs :248 (`function (string $binaryPath) use ($extDownloader): BinaryDownloader`).
**What is wrong:** The docblock claims a 3-parameter callable; the implementation declares one and relies on PHP silently discarding the extra `$version` / `$checksumManager` arguments passed at :328. Works today (userland closures accept extra args without error), PHPStan level 4 does not cross-check (the `downloadFactory` return type is plain `\Closure`, so the docblock never meets the definition), but the annotation overstates what the factory consumes and a future editor trusting it could "fix" the closure into an arity error or into silently unused params.
**Smallest safe fix:** align one side or the other — either declare the closure as `function (string $binaryPath, string $version, ChecksumManager $checksumManager)` and ignore the two, or narrow the `@param` to `\Closure(string): BinaryDownloader`.
**Automated check:** none reasonable at PHPStan level 4 (docblock-vs-declaration callable arity is not enforced; a level increase or explicit native closure shape would catch it).

## Clean checks (task items)

- **F-7 factory really roots each stub in its own directory** — verified by code trace (PluginTest.php:248-254 × Plugin.php:141/172 ext, :214/243 FFI): ext → recorded instance at `ext-binaries`, FFI → fresh failing instance at `ffi-binaries`. The stub no longer writes at all, which makes wrong-directory writes structurally impossible rather than merely rooted correctly.
- **Assertions environment-independent (ffi loaded vs not)** — no assertion touches FFI-branch output; `downloadCalls` is per-instance and the FFI stub is constructed per call (PluginTest.php:253); `=== 1` holds in both environments by trace; all 5 PluginTest tests green on this machine with ffi loaded (22 assertions). The `extension_loaded('scanmeqr')` skip (FAQ-003) is the only environment dependence, pre-existing.
- **Mismatch test fails without the unlink** — see F-8 mutation probe (RED at PluginTest.php:140; restore verified: `git diff --exit-code` → empty, `git status --porcelain` → empty).
- **PHPUnit/PHPStan correctness of new helpers** — `FailingStubBinaryDownloader::download()` signature matches the parent (`download(string $binaryName, ?string $expectedChecksum = null): string`; throws, so the `string` return is fine); `StubDownloaderPlugin::createDownloader()` overrides `protected` with the exact parent signature (LSP-clean); `final` classes; `readonly` promoted property (PHP 8.2 OK); `str_contains` 8.0+ OK. PHPStan: `[OK] No errors`.
- **PSR-12/style** — php-cs-fixer 0/55 files (clean, incl. PluginTest.php).
- **No runtime dependency added** — `git diff origin/main...HEAD -- composer.json` = 0 lines (FAQ-004 clean); `a34ab7e` touches only PluginTest.php + review artifacts.
- **No test order- or environment-dependence** — per-test `uniqid()` temp dirs with setUp/tearDown recursive delete; no statics; stub instances per-test; no network; stable across two consecutive full-suite runs (identical 5411/11565 counts and exit 0).
- **The two keeping-tests and the refuse-test pass unchanged** — untouched by `a34ab7e`; all green in the focused and full runs.
- **`a34ab7e` scope** — test-only for product code; review artifacts committed alongside (established pattern in this PoW dir).
- **No new FAQ-007 instances** — plugin catch sites unchanged (known #184); the new assertions compare user-visible output, not exception messages; the stub throws `\RuntimeException`, which never enters the message-equality `checksumMissing` branch.

## Gates

| Command | Result |
|---------|--------|
| `composer lint` | passed — cs-fixer 0/55, PHPStan `[OK] No errors` (53 files), Rector OK, kb-lint 0 warnings |
| `composer test` (full) | passed ×2 (initial + background rerun for stability) — 5411 tests / 11565 assertions, exit 0 both runs; deprecations identical to round 2's pre-existing set (8 fgetcsv + 1 PHPUnit in BuilderTest, from #57; 1 skip per FAQ-001). `a34ab7e` added none |
| `vendor/bin/phpunit tests/Composer/PluginTest.php` | OK — 5 tests / 22 assertions, zero deprecations, on PHP 8.5.9 with ffi loaded |
| Mutation probe (read-only) | `@unlink` (ext, Plugin.php:160) commented out → mismatch test RED at PluginTest.php:140; restored, `git diff --exit-code` + `git status --porcelain` clean |
| `git diff origin/main...HEAD -- composer.json` | empty (zero deps, FAQ-004) |
| `gh issue view 185 / 182` | #185 = on-disk re-verification (this PR), #182 = signature verification — DEC-006 numbers still swapped (F-4) |

## Candidate knowledge-base entries (proposed; single-writer rule — main session decides at step 14)

1. **Update DEC-006** (F-4, re-confirmed first-hand): swap the issue numbers (#185 = on-disk re-verification — implemented fail-closed by this PR; #182 = signature verification — open) and drop the stale "Existing on-disk binaries are currently accepted without re-verification (#182)" sentence on merge.
2. **FAQ-010 — "Stubbed downloaders in Composer-plugin tests: honor the seam's directory and fail, don't overwrite"** (round-2 proposal, now validated by the committed fix): *tags* tests composer security; *trigger* writing a stub for `BinaryDownloader` / overriding `createDownloader()`; *body* — (a) a success-writing stub cannot prove the code under test unlinked the old file (`fopen('wb')`/`file_put_contents` overwrite, so replace-assertions pass with or without the unlink): stub must throw and the test must assert the target is **absent** + the fallback message — this is mutation-verified in #185 round 3; (b) honor `$binaryPath` per call site — the FFI fallback (Plugin.php:118-119) reuses the same seam with a different directory; (c) CI enables `ffi` (FAQ-003), so ext-failure tests always fall through to the FFI path in CI — pin exact call counts per instance (ext stub only) so ffi-present/absent environments assert identically.
3. **New FAQ-011 candidate — "A regression test on one mirror branch does not pin its sibling"**: *tags* tests security composer ffi; *trigger* fixing a security property implemented in two mirrored branches (ext + FFI fallback in `src/Composer/Plugin.php`); *body* — #185's mismatch test covers only the ext path; the FFI branch (Plugin.php:221-232) has no committed test and can regress green (e.g. `file_exists` reintroduced, `@unlink` dropped). When a fix is mirrored, each branch needs its own planted test (tampered file at the branch's target dir → warning → target absent → fallback message), with `markTestSkipped` when the branch's prerequisite (`extension_loaded('ffi')`) is missing.

## Commands run

`composer lint`; `composer test` ×2; `vendor/bin/phpunit tests/Composer/PluginTest.php`; mutation probe (copy → sed-comment `@unlink` :160 → filtered run → restore → `git diff --exit-code`); `git diff origin/main...HEAD` (stat + composer.json); `gh issue view 185/182`; greps for stub leftovers / FFI-dir plants. Repo left pristine: the only writes were this file and the `findings-review.md` Round 3 append.
