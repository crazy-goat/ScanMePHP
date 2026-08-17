# Review round 1 — process-001 add-agent-workflow

Read `findings-review.md` first (none — first round), then looked for new
issues.

## Scope

Two commits on `process/add-agent-workflow`:
- `c69922a process: add agent workflow, dev lint tooling, drop PHP 8.1`
- `2770475 fix: resolve real PHPStan errors, shrink baseline to false positives only`

## Checks run

- `composer lint` → cs-fixer OK, phpstan OK (level 4 + baseline), rector OK,
  kb-lint OK.
- `composer test` → 5387 tests, 1821 errors, 1 failure, 1 skipped.
  Identical to the pre-change baseline (verified by `git stash` round-trip).
  All errors are the unbuilt C++ FFI library on macOS — no regression.
- `php bin/pick-issue.php --top=3` → ranks open issues (#7, #8, #29…).
- `php bin/gh-branch 8 --dry-run` → prints `feat/issue-8-imagickrenderer`,
  correctly aborts on dirty tree without `--force`.
- `php bin/kb-lint.php --fix` → regenerates tag index, exits 0.

## Findings

| # | file:line | what | severity | status |
|---|-----------|------|----------|--------|
| 1 | `bin/gh-branch:35-43` | positional arg parsing re-parses `$argv` after `getopt`, brittle if an option value starts non-`--`. Low impact for current usage. | low | open — candidate follow-up |
| 2 | `bin/pick-issue.php:42-56` | the `--paginate` fallback path duplicates the list fetch; the `||` shell fallback means a soft failure still returns the unpaginated list. Acceptable for now. | nit | open — documented |
| 3 | `phpstan.neon` | `excludePaths.analyseAndScan` uses relative paths resolved against the config file location; verified they resolve correctly from the repo root. | nit | not a real finding |
| 4 | `.gitattributes` | `/.workflow/ export-ignore` is listed after more specific `/.workflow/proof_of_work/` and `/.workflow/helpers/` lines — redundant but harmless. | nit | open — cosmetic |
| 5 | `CHANGELOG.md` | Unreleased section uses Added/Changed/Fixed/Removed in Keep-a-Changelog order; verified no duplicate section headers after the merge edit. | nit | not a real finding |
| 6 | `composer.json` | `phpunit/phpunit: ^11.5` + `brianium/paratest: ^7.6` — verified both support PHP 8.2 (the new floor). CI matrix updated to match. | nit | not a real finding |
| 7 | `src/Composer/Plugin.php` | `instanceof` guard added; `InstallOperation`/`UpdateOperation` are the correct concrete types for `POST_PACKAGE_INSTALL`/`POST_PACKAGE_UPDATE`. No test directly covers `Plugin` (e2e in CI), but the change is type-safe. | nit | not a real finding |

## Verdict

No `high`/`medium` findings. The two `low`/open findings (#1, #2) are
brittleness in the helper scripts that does not affect correctness for the
documented usage. The change is safe to merge.

## Candidate knowledge-base entries

- **FAQ-005** `tags=phpstan ffi baseline` `trigger=PHPStan reports *NEVER* or
  undefined FFI\CData property` — "These are false positives from PHPStan's
  range reasoning on QR matrix dimensions and from the FFI::cdef boundary;
  they live in `phpstan-baseline.neon` and the two FFI files are excluded.
  Do not add @phpstan-ignore; fix the source or accept the baseline entry."
