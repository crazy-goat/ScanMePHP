# Findings Review — Issue #43 (Round 1)

## Finding 1
- **File:** `src/FfiEncoder.php:92`, `tests/FfiEncoderTest.php:18`, `tests/QrReferenceTest.php:82`
- **What is wrong:** The `PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'` suffix expression is duplicated in three locations. If a new platform suffix is needed, all three must be updated in lockstep. An automated source-string contract test could catch divergence.
- **Severity:** low
- **Status:** fixed (round 1 follow-up) — extracted `FfiEncoder::localBuildPath()` static method; `resolveLibraryPath()` and both test entry points now call it. Single source of truth, no duplication. `composer lint` + full suite green. **Round 2: confirmed still fixed.** `grep` shows the `PHP_OS_FAMILY === 'Darwin'` expression appears only once (the `localBuildPath()` definition); all three call sites use `FfiEncoder::localBuildPath()`.
- **Automated check:** A source-string contract test verifying the suffix expression matches across files, or extraction of a shared `FfiEncoder::localBuildPath()` method. → Adopted the latter (shared method); divergence is now impossible by construction.

## Finding 2
- **File:** `examples/generated-assets/qrcode_dark.svg`, `qrcode_fullblocks.txt`, `qrcode_halfblocks.txt`, `qrcode_simple.txt`
- **What is wrong:** These generated asset files are modified in the working tree but are not part of the fix. They should be reverted before committing.
- **Severity:** nit
- **Status:** fixed (round 1 follow-up) — `git checkout -- examples/generated-assets/` reverted all four; working tree now contains only the #43 changes + proof-of-work (+ pre-existing untracked `review-reports/`, left untouched as out-of-scope). **Round 2: confirmed still fixed.** `git status --short` lists no `examples/generated-assets/*` entries.
- **Automated check:** `git diff --stat` pre-commit hook or CI file-change allowlist.

## Round 2 summary
Round 2 reviewed the `localBuildPath()` extraction for new issues (method
placement/visibility, docblock accuracy, tests still exercising FFI, no `.so`
local-build literal remaining, PSR-12, types, contract-test coverage). **No new
findings.** Full suite (5330 relevant tests) and `composer lint` green. See
`review-2.md` for the detailed checklist.

## Finding 3 (CI, round 1 — infra, not code)
- **File:** `.github/workflows/ci.yml` — `test (8.3)` / `test (8.4)` jobs
- **What is wrong:** PR #47 CI: 8.2 passed; 8.3 and 8.4 failed after 21 min with `##[error]The operation was canceled.` during the "Install build tools" `apt-get update` step, stuck looping on `Ign:2 http://azure.archive.ubuntu.com/ubuntu noble InRelease` (Azure Ubuntu mirror unreachable). No tests ran. Transient infrastructure/network failure, not a code defect — 8.2 passed with identical code.
- **Severity:** low (infra flake, not a regression)
- **Status:** not-a-real-finding (for this PR) — re-running the failed jobs. If it recurs persistently, the fix would be CI-side (mirror fallback / retry on apt failure), out of scope for #43.
- **Automated check:** none applicable to the product code; a workflow-level retry on the build-tools step could mask it, but that is a CI hardening task, not a #43 finding.

## Finding 3 update — 8.3 apt flake is recurring
- The `test (8.3)` job flaked identically on the re-run: passes checkout/PHP/composer, then hangs ~21 min on "Install build tools" (`apt-get update` against the Azure Ubuntu mirror) and is canceled. `test (8.2)` and `test (8.4)` pass with the same code. This is a persistent CI infra/mirror issue on the 8.3 runner, not a product-code defect. Will re-run once more; if it recurs a third time, surface to user as a CI hardening task (out of scope for #43).
