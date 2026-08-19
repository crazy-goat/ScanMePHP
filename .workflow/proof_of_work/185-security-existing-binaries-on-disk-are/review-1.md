# Review Round 1 — issue #185 (security: existing binaries on disk are never re-verified against checksums)

**Branch:** `fix/issue-185-security-existing-binaries-on-disk-are`
**Commit:** f30f337
**Reviewer:** review-critical (round 1)
**Date:** 2026-08-19

## Overall verdict: APPROVE-WITH-NITS

The change correctly implements issue #185: when a SHA-256 checksum is pinned
in the consumer's root `composer.json`, an on-disk extension/FFI binary is now
re-verified with `hash_file('sha256', …)` before being accepted; on mismatch
the file is unlinked and the existing fail-closed `BinaryDownloader::download()`
path replaces it. The fail-closed property holds in every path I traced — I
found **no way to get an unverified/mismatched binary accepted**, no regression
of the "no checksum configured" legacy path, and no new runtime dependency.

`composer lint` and `composer test` both pass (5409 tests, 11556 assertions;
the 8 deprecations are the pre-existing `fgetcsv()` PHP 8.5 ones in
`QrReferenceTest`, untouched by this branch). The touched test files pass
cleanly: 11 tests, 21 assertions, no network.

Six findings below (1 medium, 3 low, 2 nit). F-1 and F-2 are the ones worth
addressing before merge; neither is a security hole — the code is fail-closed
even in the edge cases they describe.

## What I checked

### Knowledge base (read via TAG INDEX, matching entries only)
- **FAQ-001** (ffi/phpunit): FFI test failures → build clib. N/A — FFI tests pass locally.
- **FAQ-003** (ffi): FFI optional, tests must skip not fail → `PluginTest` skips when `scanmeqr` loaded — compliant.
- **FAQ-004** (zero-deps): nothing added to `require` — `git diff origin/main...HEAD -- composer.json` is empty. **Clean.**
- **FAQ-005** (phpstan): no `@phpstan-ignore` added, no baseline edits — **clean**.
- **FAQ-006** (ffi, .so vs .dylib): no FFI path resolution touched by this diff — **clean**.
- **FAQ-007** (composer exception security): *"prefer exception codes/subclasses over message comparison; Plugin catch blocks are tracked by #184"* — the diff adds **no new** message-comparison catches; the two existing fragile sites (Plugin.php:183, 248) are untouched pre-existing debt, still tracked by #184. **Clean** (no new FAQ-007 instances).
- **FAQ-008/FAQ-009** (Builder exec/stderr): no Builder changes in this diff. **Clean.**
- **DEC-001/002** (branching/commits): branch `fix/issue-185-security-existing-binaries-on-disk-are` and commit `fix(composer): re-verify existing binaries against pinned checksums (closes #185)` comply. **Clean.**
- **DEC-003** (CI runs PHPUnit 8.2–8.4; lint local): lint run locally — passed. **Clean.**
- **DEC-005** (PHPStan level 4 + baseline; never lower gates): no baseline/level changes. **Clean.**
- **DEC-006** (consumer-pinned checksums = trust anchor): the change is a direct implementation of this decision's direction and does not weaken it — no vendor-package checksum fallback added, no fail-open download path. **Clean**, *but the entry itself now contains swapped issue numbers — see F-4.*

### Diff review (code + tests; proof-of-work prose skimmed)
Full diff `git diff origin/main...HEAD` (7 files) read: `src/ChecksumManager.php`, `src/Composer/Plugin.php`, `tests/ChecksumManagerTest.php`, `tests/Composer/PluginTest.php`, `CHANGELOG.md`, 2 proof-of-work files. Also read the calling context in `BinaryDownloader.php` and `Exception/DownloadException.php`, and cross-checked `findings-coder.md`.

### Fail-closed logic (task item 4)
- **On-disk file verified when checksum IS configured** — yes: `ChecksumManager::existingBinaryIsValid()` (ChecksumManager.php:53-65) returns `@hash_file('sha256', $path) === $checksum` when a checksum exists. Queered to Plugin at lines 149/222. Trace of every outcome when a checksum is pinned:
  - hash matches → fast-path "already exists" return (unchanged UX).
  - hash mismatches → `false` → `unlink` → warning message → **falls through** into the pre-existing `createDownloader()->download()` block — the same fail-closed path used for fresh installs (refuses without checksum, verifies SHA-256 after download, unlinks + throws on mismatch, `chmod 0755` only after verification). Sound: no second weaker code path was introduced.
  - file vanished between `file_exists()` and hash (TOCTOU, #65) → `hash_file` returns `false` with `@` suppressed → `false !== checksum` → treated as invalid → re-download. Fail-closed, quiet. I confirmed empirically `@hash_file()` on a missing path returns `false` without output noise.
  - unlink fails (permission/directory) → PHP warning only, download attempt fails at `fopen('wb')` → `DownloadException::downloadFailed` → existing catch → graceful fallback. Verified no reachable state where a mismatched file is *accepted*: either it is unlinked and replaced by a verified file, or the download fails loudly and the file is never loaded (native ext not loaded → FFI path, which verifies its own target separately; FFI failure → pure PHP).
- **Mismatch path soundness** — yes. The `checksumMissing` catch branches (Plugin.php:183, 248) cannot fire in the mismatch flow by construction (a mismatch requires a checksum to exist, so `hasChecksum()` is true); the branches behave exactly as before for the no-checksum flows. No new interaction.
- **"No checksum" path regression** — none: `existingBinaryIsValid()` returns `true` when `getChecksum()` is null (ChecksumManager.php:59-60), preserving legacy acceptance; pinned by two tests (`testExistingBinaryIsValidWhenNoChecksumConfigured`, `testPackageInstallKeepsExistingBinaryWhenNoChecksumConfigured`).

### ChecksumManager::existingBinaryIsValid()
- `hash_file` failure handling: quiet-false via `@` — suppression precedent confirmed in-tree (`@file_get_contents('/proc/version')`, PlatformDetector.php:43). The fail-closed semantics *depend* on the quiet failure, so the `@` is justified and commented; PHPStan level 4 passes. Not a finding.
- Return semantics: documented by inline comments; exactly the three cases the issue required. Clean.
- `getChecksum()` version-key normalization reused (handles `v0.4.4` / `0.4.4`) — no duplicated logic.

### Tests (task item 4)
- **Do they pin the behavior?** The decision logic is well pinned: 4 new `ChecksumManagerTest` cases (match / mismatch / no-checksum / missing-file). The two new `PluginTest` cases pin the network-free fast paths end-to-end (matching-checksum "already exists" with the file bit-for-bit untouched; no-checksum legacy acceptance).
- **No network:** verified — every committed test either returns before `BinaryDownloader` is reached, or reaches it only in the fail-closed `checksumMissing` branch which throws *before* any cURL call (`BinaryDownloader.php:44-53`).
- **Fail loudly on naming drift:** yes. `extensionBinaryName()` mirrors the plugin naming; if the plugin naming changes, the plugin looks up a name for which no file/checksum exists → fail-closed refusal fires before any HTTP → the string assertions (`already exists`, `NotContainsString('refused')`) fail loudly without network. Verified by reading `runPackageInstall` + the refusal path.
- **Gap:** the mismatch orchestration (unlink + re-download fall-through) — the very branch this issue is about — has **no committed regression test** (F-1). The coder verified it once with a throwaway networked test and deleted it. Defensible rationale, but it is the one piece of the change that a future refactor could break with no test failing.

### Zero-deps / style / AGENTS.md
- `composer.json` untouched (FAQ-004 clean).
- PSR-12/php-cs-fixer, PHPStan (level 4), Rector, kb-lint: all pass (`composer lint`).
- Conventions: strict_types, typed signatures, no docblocks where not needed, comments explain the security rationale. Clean.

### Security threat model (task item 4)
- Attacker with read-only access to `vendor/`: cannot influence the hash comparison (checksum comes from the consumer's root composer.json per DEC-006 / #48); a tampered-on-disk binary is now detected and replaced at install time instead of being accepted forever. Exactly the #185 goal.
- Attacker with write access to `vendor/`: can still swap the file *after* verification / at runtime — this is acknowledged residual risk tracked by #65 (TOCTOU/non-atomic write) and #182 (signature verification), out of scope per the issue body. The change narrows the exposure window (stale/cached poisoned binaries are removed at install) and does not widen it.
- `unlink()` on the target is not security-critical: the fall-through download either replaces the file (verified) or fails loudly; a stale file is never re-accepted.

## Findings

### F-1 — Mismatch orchestration (unlink + re-download) has no committed regression test | severity: medium | status: open
**Where:** `src/Composer/Plugin.php:157-161` (ext) and `:228-232` (FFI); missing test in `tests/Composer/PluginTest.php`.
**What is wrong:** The branch this issue exists for — "on-disk file fails re-verification → unlink → re-download through the verified path" — is exercised only by a deleted throwaway networked test. The committed tests pin the *decision* (`ChecksumManagerTest`) and the two *keep* fast paths (`PluginTest`), but nothing pins that the plugin actually unlinks a mismatched file and falls into `BinaryDownloader::download()`; a future refactor (e.g. someone "simplifying" the exists-block into an unconditional early return) would break #185 with a green suite.
**Why it is not a blocker:** the coder verified the flow end-to-end (tampered file → unlinked → real download → checksum rejection → graceful fallback), and any regression would be a *fail-open* one that the decision tests would not catch but the orchestration is only ~10 lines per call site.
**Smallest safe fix:** add a small test seam to `Plugin` (a `protected function createDownloader(...)` override, or a constructor-injectable downloader factory — the coder rejected this as over-engineering, but "Prefer a gate over an entry" (helpers README) says the regression test is the gate and a 3-line seam is the cheapest one), then a test: tampered on-disk file + matching pinned checksum → assert file was unlinked, "Re-downloading" warning present, downloader invoked (stub throws `DownloadException::downloadFailed`), graceful fallback message. Offline, fast, deterministic.
**Automated check that could catch this:** the PHPUnit test itself (per above).

### F-2 — `file_exists()` accepts a *directory* at the target path → misleading message + permanent install blocker | severity: low | status: open
**Where:** `src/Composer/Plugin.php:148` and `:221` (`file_exists($targetFile)`), interplay with `ChecksumManager.php:64`.
**What is wrong:** `file_exists()` is true for directories. Verified empirically: with a directory named like the target binary, `existingBinaryIsValid()` returns `false` (correct, `@hash_file` on a dir → `false`), then `unlink($targetFile)` **fails** (unsuppressed PHP warning "Is a directory"/"Operation not permitted"), the code prints "⚠️ … failed SHA-256 verification" (inaccurate — nothing was verified; the path is not a file), and the subsequent download fails at `fopen('wb')` ("Failed to open target file"). The directory is never removed, so **every subsequent `composer install` repeats the same failure** — a native-binary install DoS that survives retries, and a confusing message trail. Fail-closed still holds (nothing is accepted); this is a robustness/UX gap, not a bypass. The coder's code-decision-1.md acknowledges the case but dismisses it as "acceptable" — it is acceptable *securely*, but the lasting blocker and the wrong message are avoidable.
**Smallest safe fix:** switch the two exists-checks to `is_file($targetFile)` (a directory then skips the exists-block and fails at download with the accurate "Failed to open target file" — same graceful end state, one fewer warning, no misleading checksum message), or explicitly detect and `rmdir()` the directory.
**Automated check that could catch this:** a PHPUnit test planting a directory at `$targetFile` and asserting a graceful single failure message.

### F-3 — Warning is printed *after* `unlink()`, and unlink failure is invisible | severity: low | status: open
**Where:** `src/Composer/Plugin.php:159-160` and `:230-231`.
**What is wrong:** Task asked specifically whether the warning precedes the unlink — it does not (`unlink()` at :159/:230, message at :160/:231). If unlink fails, the user sees "Re-downloading the verified binary" followed by an unexplained download failure; the PHP warning from `unlink()` is unsuppressed. No security impact (traced above: a failed unlink can never lead to accepting the mismatched file), purely diagnostic quality.
**Smallest safe fix:** reorder to print the warning first, then `unlink`; optionally `@unlink` since a failure is benign-in-effect (the download either replaces the file or fails loudly). If F-2's `is_file()` change lands, the directory case disappears and the remaining unlink failures are permission-only.
**Automated check that could catch this:** none reasonable (cosmetic/diagnostic).

### F-4 — KB decisions.md DEC-006 has swapped issue numbers and goes stale on merge | severity: low | status: open (KB, main session action)
**Where:** `.workflow/helpers/decisions.md:98-100` (DEC-006).
**What is wrong:** DEC-006 says *"Signature verification (minisign/cosign) is a known future enhancement (#185)"* and *"Existing on-disk binaries are currently accepted without re-verification (#182)"*. Verified against `gh issue view`: **#185 is the on-disk re-verification issue (implemented by this PR); #182 is signature verification.** The numbers are swapped, and the second sentence becomes stale the moment this PR merges. A future agent reading DEC-006 would be pointed at the wrong issues.
**Smallest safe fix (KB, single-writer rule — main session's job):** update DEC-006: correct the numbers, and note that #185 (on-disk re-verification) is implemented — fail-closed — while #182 (signature verification) remains open.
**Automated check that could catch this:** none (prose KB; could not be linted for issue-number accuracy).

### F-5 — Test name overpromises ("MissingOrUnreadable" tests only the missing case) | severity: nit | status: open
**Where:** `tests/ChecksumManagerTest.php:210`.
**What is wrong:** `testExistingBinaryIsInvalidWhenFileMissingOrUnreadable` only exercises the missing-file case; the unreadable case (chmod 000) is not tested (not portable: root ignores 000, Windows semantics differ). The missing-file case does cover the `@hash_file → false` branch, which is the same code path.
**Smallest safe fix:** rename to `testExistingBinaryIsInvalidWhenFileMissing` (honest scope), or add a chmod-000 case that skips when `posix_geteuid() === 0` / on Windows.
**Automated check that could catch this:** none (naming).

### F-6 — CHANGELOG uses `### Security`, which is not in AGENTS.md's documented section list | severity: nit | status: open
**Where:** `CHANGELOG.md:63-69`.
**What is wrong:** AGENTS.md documents sections `Added / Changed / Fixed / Removed`; the two previous security fixes (#48, #57) landed under `### Fixed`. Keep a Changelog 1.1 does formally allow a `Security` section, and the entry itself is correct and complete (under `## [Unreleased]`, references #185, describes the change accurately) — so this is consistency, not compliance.
**Smallest safe fix:** either move the entry under `### Fixed` (matching #48/#57 precedent) or update AGENTS.md's section list to include Security.
**Automated check that could catch this:** none (style).

## High-risk areas verified clean

| Area | Result |
|------|--------|
| Fail-closed on mismatch / unhashable / vanished file | **Clean** — every path returns `false` → unlink → verified re-download; a failed re-download never re-accepts the stale file (traced all combinations) |
| No-checksum legacy path regression | **Clean** — `null` checksum → `true`, pinned by two tests |
| checksumMissing catch branches (Plugin.php:183, 248) | **Clean** — unreachable from the new mismatch flow by construction; no new message-comparison sites (FAQ-007 / #184 untouched) |
| TOCTOU (#65) | **Clean w.r.t. this change** — `@hash_file` degrades a vanished-file race to quiet fail-closed; residual verify-then-load window acknowledged, tracked by #65/#182, out of issue scope |
| Zero runtime deps | **Clean** — composer.json untouched (FAQ-004) |
| Gates | **Clean** — `composer lint` (cs-fixer, phpstan, rector, kb-lint) and `composer test` pass; touched files 11 tests / 21 assertions OK, no deprecations, no network |
| Style / conventions | **Clean** — strict_types, typed signatures, project message style, CHANGELOG entry present (F-6 nit aside) |

## Candidate knowledge-base entries (proposed; main session decides)

1. **Update DEC-006** (existing entry, not new): correct the swapped issue numbers (#185 = on-disk re-verification, implemented fail-closed; #182 = minisign/cosign/GPG signature verification, still open). *Tags:* security composer ffi. *Trigger:* reading DEC-006 after this PR merges, or citing #182/#185 in a security decision.
2. **New FAQ — "Existence checks that feed `unlink()`/`hash_file()` must be `is_file()`, not `file_exists()`"**: *Tags:* security composer. *Trigger:* writing `if (file_exists($path))` followed by `unlink()` or `hash_file()` on a binary/cache path. *One paragraph:* `file_exists()` is true for directories; hashing a directory fails quietly (fail-closed) but `unlink()` on it fails with an unsuppressed warning, leaving a permanent blocker that every subsequent install hits, plus a misleading "checksum verification failed" message. Use `is_file()` for existence checks that feed unlink/hash — the failure then surfaces once, at the write step, with an accurate message. (Root cause of finding F-2; the F-1-style regression test would be the preferred gate once the fix lands.)

## Commands run

| Command | Result |
|---------|--------|
| `git diff origin/main...HEAD` (stat + full src/tests/CHANGELOG) | reviewed — 7 files, 465 insertions / 16 deletions |
| `composer lint` | passed — cs-fixer 0/55, phpstan `[OK] No errors`, rector OK, kb-lint 0 warnings |
| `composer test` | passed — 5409 tests, 11556 assertions, exit 0 (8 pre-existing fgetcsv deprecations in QrReferenceTest, untouched here) |
| `vendor/bin/phpunit tests/ChecksumManagerTest.php tests/Composer/PluginTest.php` | OK — 11 tests, 21 assertions, no deprecations |
| PHP CLI edge probes | `@hash_file` on dir → `false` quiet; `hash_file` unsuppressed → Notice; `unlink` on dir → warning + `false` |
| `gh issue view 185 / 182 / 65 / 184` | #185 = re-verification (this PR), #182 = signature verification, #65 = TOCTOU, #184 = message-equality catches — used to verify DEC-006 accuracy (F-4) |
| `git diff origin/main...HEAD -- composer.json` | empty — zero-deps clean |
