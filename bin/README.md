# bin/ — workflow helper scripts

## `gh-branch` — create a feature branch from a GitHub issue

```bash
bin/gh-branch <issue>                 # creates/switches to <type>/issue-<n>-<slug>
bin/gh-branch <issue> feat            # force type (fix|feat|docs|perf|refactor|chore|test|build|ci|process)
bin/gh-branch <issue> process         # workflow/tooling change — required for protected paths
bin/gh-branch <issue> --push          # also push with upstream
bin/gh-branch <issue> --dry-run       # print the name without touching git
branch="$(bin/gh-branch <issue>)"     # capture the name (printed to stdout)
```

The branch type is inferred from a `[Type]` title prefix, falling back to issue
labels, then to `fix`. The branch is created from the fresh remote `main`. A
dirty tree on `main` aborts creation unless `--force` is given. If the branch
already exists locally or on origin, it switches to it.

Exit codes: `0` success, `1` usage/error, `2` git dirty/non-default abort.

## `pick-issue.php` — rank open issues

```bash
php bin/pick-issue.php                # top 5 of all open issues
php bin/pick-issue.php --top=10       # top 10
php bin/pick-issue.php --json         # machine-readable
```

Scores every open issue by type labels, priority labels, title signals, age and
comment count. Never reads issue bodies or comment text. Paginates (`gh` caps
lists at 30 by default). The LLM/user still makes the final pick.

Exit codes: `0` candidates printed, `1` error, `2` no open issues.

> Unlike the workerman-bundle version there is **no milestone release gate** —
> ScanMePHP has no milestones, so every open issue is eligible (exit code `3`
> is never produced here).

## `kb-lint.php` — validate the knowledge base

```bash
php bin/kb-lint.php                   # check only
php bin/kb-lint.php --fix             # rewrite tag indexes
```

Validates that every `###` entry in `.workflow/helpers/faq.md` and
`.workflow/helpers/decisions.md` carries single-line front matter in an HTML
comment (`id`, `date`, `tags`, `trigger`, `status`). Regenerates the tag index
at the top of each file with `--fix`. Warns above 300 lines per file and lists
`stale` entries. Runs inside `composer lint`.

Exit codes: `0` ok, `1` lint errors, `2` no helpers dir.
