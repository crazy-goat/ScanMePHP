# Review findings — process-001 add-agent-workflow

One entry per finding: `file:line`, what is wrong, severity, what happened
to it. Appended across rounds.

## Round 1

- `bin/gh-branch:35-43` — positional arg parsing re-parses `$argv` after
  `getopt`; brittle if an option value starts with non-`--`. Severity: low.
  Status: **open** — candidate follow-up; does not affect documented usage.
- `bin/pick-issue.php:42-56` — the `--paginate` fallback duplicates the list
  fetch via shell `||`; a soft failure returns an unpaginated list.
  Severity: nit. Status: **open** — documented, acceptable for now.
- `phpstan.neon` — `excludePaths.analyseAndScan` relative path resolution.
  Severity: nit. Status: **not a real finding** — verified resolves from
  repo root.
- `.gitattributes` — redundant `/.workflow/` line after specific subpaths.
  Severity: nit. Status: **open** — cosmetic, harmless.
- `CHANGELOG.md` — section order/duplicates. Severity: nit. Status: **not a
  real finding** — verified after merge edit.
- `composer.json` — phpunit/paratest PHP 8.2 support. Severity: nit.
  Status: **not a real finding** — verified both support 8.2.
- `src/Composer/Plugin.php` — instanceof guard type safety. Severity: nit.
  Status: **not a real finding** — correct concrete types for the events.
