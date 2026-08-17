# Code decision 1 — process-001 add-agent-workflow

The approach taken in round 1, what was rejected, and what was uncertain.

## Approach

Adapt the workerman-bundle `docs/workflow.md` to ScanMePHP realities rather
than copy it verbatim. The adaptation is one self-contained change delivered
in two commits:

1. `c69922a` — introduce the workflow, helpers, proof-of-work dirs, `bin/`
   scripts, `composer lint`/`lint-fix` tooling, drop PHP 8.1, run the whole
   codebase through php-cs-fixer + rector.
2. `2770475` — fix the real PHPStan errors (match.unhandled ×2,
   method.notFound in Composer\Plugin, variable.undefined in kb-lint),
   exclude the two FFI-boundary files, regenerate the baseline.

## What was rejected

- **Copying `docs/` verbatim.** Rejected: `docs/` is gitignored here by
  maintainer choice (`b6652e1`). Using `.workflow/` (dotfile, like `.github/`)
  keeps the workflow out of the gitignored path without fighting the rule.
- **Keeping the milestone release gate.** Rejected: ScanMePHP has no
  milestones. `bin/pick-issue.php` was rewritten to rank all open issues by
  labels/age/comments; exit code 3 is never produced.
- **Adding a coverage floor.** Rejected: CI runs `coverage: none`; adding a
  floor requires Xdebug/PCOV in CI and a maintainer decision on the
  percentage. Documented as FAQ-002 instead.
- **Wiring lint jobs into CI in this PR.** Rejected: kept the change
  focused on tooling + workflow. CI lint jobs are a separate, follow-up
  change (DEC-003 says "run `composer lint` locally until then"). Adding
  them now would also require deciding required-status rules, which is a
  maintainer call.
- **Suppressing the real PHPStan errors with `@phpstan-ignore` or baseline.**
  Rejected: workflow §6 forbids it. The four real errors were fixed at the
  source (match defaults, instanceof guard, `$_SERVER['argv']`).
- **Including `composer.lock`.** Rejected: it is gitignored by choice
  (DEC-004). CI installs fresh per leg.

## What was uncertain

- Whether to exclude `FfiEncoder.php` from PHPStan or write FFI stubs.
  Chose exclusion for consistency with `NativeEncoder.php` (both are FFI
  boundary code). Stubs would be cleaner long-term but are a bigger change.
- The right PHPStan level to start at. Settled on **level 4** because levels
  0–3 add nothing the project does not already pass, and level 4 is the first
  that surfaces the `match.unhandled` and `*NEVER*` range issues. Higher
  levels (5+) explode with `missingType.iterableValue` across the renderer/
  encoder code and would need a large type-annotation effort — tracked as
  future work in DEC-005.
- Whether the 76 remaining baseline errors are all false positives. Verified
  by categorising: 7× `method.alreadyNarrowedType` (PHPUnit assertions on
  already-narrowed types in tests) and 14× `identical.alwaysFalse`/
  `greaterOrEqual.*`/`booleanAnd.alwaysFalse` in `FastEncoder`/`MaskSelector`
  (PHPStan's `*NEVER*` range reasoning does not model QR matrix dimensions).
  None are real bugs.
