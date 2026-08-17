# Workflow: Issue → Feature Branch → Implementation → Review Rounds → PR → CI → Merge

This document describes the complete workflow for handling issues in the
[crazy-goat/ScanMePHP](https://github.com/crazy-goat/ScanMePHP) repository
using `gh` and `git`. It is adapted from the workerman-bundle workflow to the
realities of this project (see [Differences from the source
workflow](#differences-from-the-source-workflow) below).

Every cycle leaves a **proof of work** under
`.workflow/proof_of_work/<issue>-<slug>/`: four kinds of Markdown file,
written by the agents that did the work and committed on the branch. See
[Proof of Work](#proof-of-work-workflowproof_of_work) below and
[proof_of_work/README.md](proof_of_work/README.md). Nothing enforces them.
They are read during review, like the code.

---

## 1. Browse Open Issues via Subagent

Browsing and triaging open issues is token-heavy (titles, bodies, labels,
comments, related code). Delegate it to a subagent with its own context.

```bash
# The subagent receives a task like:
# "List the top 5 most impactful open issues in crazy-goat/ScanMePHP.
# For each, return: number, title, labels, one-paragraph rationale.
# Do NOT propose branch names — bin/gh-branch derives them in step 2.
# Prioritize: enhancement, good-first-issue, code-quality,
# stability/data-correctness/performance, blockers, user-facing (README/API docs)."
```

The subagent uses `gh issue list --state open --limit 100` and
`gh issue view <n> --json title,body,labels,state` to gather data, then
returns a ranked shortlist. The main session picks one issue from the
shortlist and proceeds to step 2.

> **Note:** `gh issue list` returns **at most 30 issues by default** — the
> triage task must explicitly raise `--limit` (e.g. `--limit 100`, max 1000)
> so issues beyond the first page are not missed.

> **Why a subagent:** issue bodies, comments, and related code can easily
> exceed thousands of tokens. Keeping this in a separate context protects the
> main session's budget for implementation and review.

### Fast path: ranked candidates via `bin/pick-issue.php`

ScanMePHP has **no milestones**, so there is no release gate here — every open
issue is eligible. Before delegating triage to a subagent, run the ranking
script — it costs a few tokens instead of thousands:

```bash
php bin/pick-issue.php                # top 5 of all open issues
php bin/pick-issue.php --top=10       # top 10
php bin/pick-issue.php --json         # machine-readable, for scripting
```

The script scores every open issue — type labels (`bug`/`security`/
`enhancement`/…), priority labels (`critical`/`high`/`medium`/`minor`), title
signals (leak/crash/security/performance), age and comment count — and prints
the top N with an explicit per-issue score breakdown. It never reads issue
bodies or comment text: the API payload is projected down to titles, labels,
dates and comment counts at parse time, and it paginates (`gh` caps lists at
30 by default). The LLM/user still makes the final pick from the ranked
candidates; the script only narrows the pool.

**Selection criteria (applied by the subagent):**

- Issues labeled `enhancement`, `good-first-issue`, `code-quality`
- Issues about stability, data correctness, performance
- Issues blocking other tasks
- Issues most relevant to users (README, API documentation)

---

## 2. Create a Fresh Feature Branch

Run the helper — the branch name is derived from the issue, nobody (human or
LLM) has to invent it:

```bash
bin/gh-branch <issue>                 # creates/switches to <type>/issue-<n>-<slug>
bin/gh-branch <issue> feat            # force type (fix|feat|docs|perf|refactor|chore|test|build|ci|process)
bin/gh-branch <issue> process         # workflow/tooling change — required for protected paths
bin/gh-branch <issue> --push          # also push with upstream
branch="$(bin/gh-branch <issue>)"     # capture the name (printed to stdout) for later steps
```

The `fix`/`feat`/… type is inferred from a `[Type]` title prefix
(`[Bug]`→`fix`, `[Feat]`→`feat`, `[Tests]`→`test`, `[Process]`→`process`, …),
falling back to issue labels (`bug`/`security`→`fix`, `enhancement`→`feat`,
`documentation`→`docs`, `process`→`process`, …), and finally to `fix`. An
issue labelled `process` therefore lands on a `process/` branch — use that
prefix for changes to the workflow itself (`docs/workflow.md`,
`.github/workflows/*`, the `scripts` block of `composer.json`, `bin/*`,
`.workflow/*`), so that "we changed the rules" is visible in the branch name.

The branch is created from the fresh remote `main`, so no manual fetch/pull
is needed. If the branch already exists (locally or on origin) it switches to
it instead; a dirty working tree or being on a non-default branch aborts
**creation** — use `--force` to proceed anyway (uncommitted changes are then
carried to the new branch, exactly as with `git switch -c`). Use `--dry-run`
to see the name without touching git.

**Branch naming convention:** `<type>/issue-<n>-<slug>` (e.g.
`feat/issue-8-imagickrenderer`) — the script produces exactly this shape.

Then make the directory this cycle's proof of work lives in — `<n>` is the
zero-padded issue number, `<slug>` a short kebab-case description. It does
not depend on a PR, so create it now:

```bash
mkdir -p .workflow/proof_of_work/$(printf '%03d' <issue>)-<slug>
```

Four kinds of file end up there: `findings-coder.md`, `findings-review.md`,
`code-decision-<N>.md` and `review-<N>.md`, where `<N>` is the round of the
inner loop. They are written by the subagents that do the work and committed
on the branch like any other change. See
[proof_of_work/README.md](proof_of_work/README.md).

---

## 3. Implement the Change (via Worker/Coder Subagent)

Implementation is delegated to a subagent (`worker` or `coder`) so the main
session stays free to orchestrate, review findings, and handle the next steps.

```bash
# The subagent receives a task like:
# "Implement issue #<n> on branch feat/issue-<n>-<slug>.
# Read .workflow/helpers/ first, via the TAG INDEX: load the index at the top of
# faq.md / decisions.md, pick the tags matching the files you will touch, and
# read only those entries — never the whole file.
# You do NOT write to .workflow/helpers/; propose candidate entries in your report.
# Read the issue body first, then make the smallest correct change.
# Run the relevant tests for the changed behavior (composer test, or a filtered
# vendor/bin/phpunit --filter ...).
#
# Write two files under .workflow/proof_of_work/<issue>-<slug>/:
# - code-decision-1.md: the approach you took, what you rejected and why, and
#   anything you were unsure about
# - findings-coder.md (append if it exists): what you found along the way —
#   obstacles, surprises, and any bugs or weak spots you noticed, INCLUDING
#   ones outside this issue's scope, each with file/line and a suggested fix
#
# Commit and push everything, the two files included."
```

After the subagent reports, commit and push if it did not do so already:

```bash
git add -A
git commit -m "feat(encoding): implement <short> (closes #<n>)"
git push origin feat/issue-<n>-<slug>
```

**Commit message convention** (see `decisions.md` DEC-002):

- Type: `feat`, `fix`, `docs`, `refactor`, `ci`, `test`, `chore`, `perf`, `build`
- Scope: `(encoding)`, `(renderer)`, `(exception)`, `(composer)`, `(ffi)`,
  `(config)`, `(core)`, `(ci)`, `(process)` — map to `src/` subdirs or concerns
- Reference to issue: `(closes #<n>)`

> **Coder output contract (non-negotiable):** the subagent must always report
> (1) changed files, (2) the biggest problem it faced with details, and
> (3) any discovered bugs / places to improve — even ones outside the current
> issue's scope — and must write (2) and (3) into `findings-coder.md` rather
> than leaving them in chat. The main session reuses them for the final report
> (step 14).

---

## 4. Code Review via Subagent

After implementation, run a code review using a subagent (separate agent with
its own context). The subagent checks:

- Alignment with project structure (PSR-4, the `ScanMePHP\` namespace)
- Type correctness and signatures (PHPStan, when configured)
- Error handling and edge cases
- Coding style (PSR-12, php-cs-fixer, when configured)
- Test coverage
- **Zero-runtime-dependency principle** — nothing new in `require`
- Security (binary download, FFI boundary, file writes)

**Review round `<N>` reads `findings-review.md` first.** Before looking for
anything new it goes through what earlier rounds recorded and says, for each,
whether it is still present. Nothing is deleted from that file — a finding the
coder believes fixed and the review still sees is a disagreement worth keeping
on the record.

```bash
# The subagent receives a task like:
# "Code review the changes in files: <list>.
# Read .workflow/helpers/ first, via the TAG INDEX (index + only the entries whose
# tags match the files in the diff), and flag any violations of documented
# decisions by entry id. You do NOT write to .workflow/helpers/ — propose
# candidate entries in your report.
# Then read .workflow/proof_of_work/<issue>-<slug>/findings-review.md and, for
# every finding an earlier round left open, state explicitly: still present /
# fixed / not a real finding (with evidence). Only then look for NEW issues.
# Check: type correctness, error handling, PSR-12 compliance, missing tests,
# outdated documentation, and that no runtime dependency was added to require.
# For a diff touching .github/workflows/*, do NOT scope your test run to the
# single most obvious test — other classes may pin the same YAML
# (grep -rl '<thing>' tests/), so either sweep or run the full suite.
#
# Write two files under .workflow/proof_of_work/<issue>-<slug>/:
# - review-<N>.md: your full review for this round
# - findings-review.md (append if it exists): one entry per finding —
#   file:line, what is wrong, severity, and what happened to it
#
# For any finding an automated check could plausibly have caught, say which
# check that would be. If the same class of defect has been seen before,
# write the check in this PR rather than reporting it again."
```

Severities are `high`, `medium`, `low`, `nit`. Anything non-obvious the review
learned is reported as a **candidate** knowledge-base entry (title, tags,
trigger, one paragraph). The review does not write to `.workflow/helpers/` —
only the retro step does, see [Knowledge Base](#knowledge-base-workflowhelpers)
below.

> **Why a subagent:** code review reads the full diff plus surrounding code,
> runs static analysis, and produces a structured findings list. Delegating
> keeps the main session focused on fixes and the next workflow step.

---

## 5. Fix the Findings

```bash
# For each finding:
# 1. Apply the fix
# 2. Note in findings-review.md what happened to it
# 3. Commit
git add -A
git commit -m "fix: <short>"
git push origin feat/issue-<n>-<slug>
```

**All findings get an answer — even the `nit`s.** Fixed, deliberately not
fixed (say why, and cite `.workflow/helpers/decisions.md` if there is an
entry), or not a real finding (say what the evidence was). Silence is not an
answer.

> **A finding first seen in round 2 or later escaped round 1.** That usually
> means a check was missing rather than that a reviewer was unlucky — prefer
> adding the test over just fixing the line.

---

## 6. Repeat the Review

After fixing, invoke the review subagent again for round `<N+1>`. It writes
`review-<N+1>.md` and appends to `findings-review.md`. Repeat steps 5 → 6
until the review reports no open findings.

**Commit the review's files after every round — including a clean one.** The
review subagent writes `review-<N>.md` and appends to `findings-review.md` but
never commits (it is read-only by contract). After a round with fixes, step
5's `git add -A` sweeps those files up; after a **clean** round there is no
fix commit, so the main session must commit them itself — otherwise the
round's record silently never makes it into the merged PR:

```bash
# after EVERY round, clean or not — before moving on to linting
git add .workflow/proof_of_work/<issue>-<slug>/
git commit -m "docs: record review round <N> for #<n>"
```

Uncommitted review files are not proof of work yet. Four rounds is a lot. A
loop that has not converged by then usually needs a decision rather than
another iteration — narrow the issue and file the rest separately, throw the
approach away and re-plan, or ask the user. Say which one you chose, in the
last `code-decision-<N>.md`.

> **Never lower a gate to reach a clean round.** Dropping a coverage floor or
> disabling a linter rule to make a round look clean is forbidden outright
> (see `.workflow/helpers/decisions.md`).

---

## 7. Run Linters and Tests Locally

Before opening a PR, verify that all linters and tests pass on your machine:

```bash
# Run all linters (php-cs-fixer dry-run, phpstan, rector dry-run) and the
# knowledge-base linter (php bin/kb-lint.php)
composer lint

# Auto-fix fixable issues (php-cs-fixer, rector, kb-lint --fix)
composer lint-fix

# Run tests (PHPUnit across the suite; no daemon, no ports)
composer test
```

> **Note:** CI does **not** run a coverage floor yet (see `faq.md` FAQ-002).
> If your PR adds meaningful logic and you want to check coverage locally, run
> `vendor/bin/phpunit --coverage-text` with Xdebug/PCOV enabled.

> **Note:** ScanMePHP has no background daemon. Unlike the workerman-bundle
> workflow, there are no ports to free and no server to stop (see `faq.md`
> FAQ-001). The FFI tests do require the C++ library to be built:
> `cd clib && cmake -B build -S . && cmake --build build -j`.

After `composer lint-fix`, commit any fixes:

```bash
git add -A
git commit -m "style: auto-fix lint issues"
```

**Only open the pull request (step 9) when all lints and tests pass locally.**

---

## 8. Update CHANGELOG.md

```bash
# Edit CHANGELOG.md:
# - Add entry under [Unreleased] section
# - Follow Keep a Changelog format (https://keepachangelog.com/en/1.1.0/)
# - Use appropriate section: Added, Changed, Fixed, Removed, Deprecated
# - Include issue number, e.g. (#8)
```

See the project's `AGENTS.md` for the CHANGELOG rules — `version` must NOT be
present in `composer.json` (Packagist uses git tags), and on release the
`[Unreleased]` entries move to a new `[X.Y.Z] - YYYY-MM-DD` section.

---

## 9. Open the Pull Request

The PR is created here — **after** implementation and after step 7's linters
and tests pass locally. There is nothing for a PR to converge before there is
content. The issue is linked from the first push regardless: GitHub
auto-closes the issue from the `Closes #<n>` line in the PR body.

```bash
gh pr create \
  --title "feat: <short> (closes #<n>)" \
  --body "## Description
Closes #<n>

## Changes
- ...

## Changelog
- Added/Fixed: ...

## Proof of Work
\`.workflow/proof_of_work/<issue>-<slug>/\` — <N> review round(s)

## Code Review
- [ ] Passed subagent code review
- [ ] Every finding answered" \
  --base main --assignee @me
```

The PR is created ready — the review rounds (steps 4-6) already happened on
the branch itself, and CI runs on the PR from its first push.

> **Note:** `main` is **not** protected by a ruleset in this repo (unlike
> workerman-bundle's `master`), but the workflow still forbids direct pushes
> to `main` — always use a PR (see `decisions.md` DEC-001). The merge
> decision remains the maintainer's own.

---

## 10. Wait for CI

```bash
gh pr view <n> --json statusCheckRollup   # check PR status
gh pr checks --watch                       # wait for all checks to finish
```

CI (`.github/workflows/ci.yml`) triggers on push to `main`/`master`/`develop`
and on pull requests to those branches. It has two jobs:

1. **check-permissions** — skips CI for actors without write access (repo
   owner is always allowed).
2. **test matrix** (PHP 8.1–8.4) — `composer validate --strict`, install,
   build the C++ FFI library, `composer test`.

> **Note:** lint jobs (phpstan, php-cs-fixer) are **not** wired into CI yet.
> Run `composer lint` locally before opening a PR (see `decisions.md` DEC-003).

---

## 11. Handle CI Failures

If CI fails:

```bash
gh pr checks                              # see which checks failed
gh run view --log --job <job-id>          # view logs
# fix the issues locally
# run code review via subagent again (repeat steps 4-6)
git add -A
git commit -m "fix: <short>"
git push origin feat/issue-<n>-<slug>
gh pr checks --watch
```

> **Note:** There is no pre-push lint hook in this repo yet. CI is the gate.

**Repeat until all CI checks pass.**

> **A CI failure is an escaped defect.** Record it in `findings-review.md`
> like any other finding before fixing it — round 1 should have caught it and
> did not, which is usually a missing check rather than bad luck.

---

## 12. Merge PR and Close Issue

```bash
gh pr merge <n> --squash --delete-branch
# the issue auto-closes if the squash commit contains "closes #<n>"
# alternatively: gh issue close <n>
```

> **Note:** Because `main` is not ruleset-protected, `gh pr merge` is not
> blocked by a required `ci` aggregator. The maintainer's own judgement and
> green CI are what gate a merge (see `decisions.md` DEC-001).

---

## 13. Switch Back to main

```bash
git checkout main
git pull origin main
```

---

## 14. Report Implementation Problems and Offer a GitHub Issue

At the end of the workflow, present the findings collected during the cycle
and decide with the user whether they deserve a dedicated GitHub issue. They
are already written down — read them out of
`.workflow/proof_of_work/<issue>-<slug>/findings-coder.md` and
`findings-review.md` rather than out of the chat log, which may since have
been compacted.

**Display to the user:**

1. **Biggest problem(s) faced during implementation** — as reported by the
   worker/coder subagent in step 3.
2. **Discovered bugs / places to improve** — each with file/line, short
   description, and suggested fix (including findings outside the scope of the
   issue just closed).

**Verify each candidate finding with a review subagent (read-only) before
offering or creating an issue.** For every candidate finding the subagent must
confirm:

1. **The finding is real** — read the cited file/line(s) on the current branch
   and confirm the behavior actually occurs and is reachable; check whether it
   is by-design and already documented (those are skipped, not filed).
2. **No similar issue exists on GitHub** — search open *and* closed issues.
   `gh issue list` returns at most 30 issues by default, so always pass an
   explicit limit:

   ```bash
   gh issue list --state open --limit 150 --json number,title,labels,body
   gh issue list --state closed --limit 150 --json number,title,labels
   gh search issues --repo crazy-goat/ScanMePHP --state open --limit 50 "<term>"
   ```

   Same or overlapping scope counts as tracked; known related issues (e.g.
   referenced from CHANGELOG entries) must be checked explicitly.

3. **A recommendation per finding**: (a) create a new issue — with proposed
   title and labels per the project's conventions (`bug` / `enhancement` /
   `code-quality` / `good-first-issue` / …), (b) skip — already tracked (cite
   the issue number), or (c) skip — not real or by-design and documented.

The verification subagent must not modify files and must not create/close/edit
issues itself. Like steps 3 and 4, it reads `.workflow/helpers/` first — tag
index plus the entries matching the files in the diff — and writes nothing
there. Only findings that pass verification (real + untracked) are offered to
the user / created.

**Then ask:** "Create GitHub issue(s) for these findings?"

- If yes, create an issue via `gh` (adjust labels to the project's
  conventions):

  ```bash
  gh issue create \
    --title "<title>" \
    --body "## Description
<what>

## Where
- <file>:<line>

## Suggested fix
<how>" \
    --label bug
  ```

  Assign `--label bug` for confirmed bugs or `enhancement` / `code-quality`
  for improvement candidates. One issue per distinct finding keeps them
  actionable.

- If the user declines or the findings are already tracked, just record the
  outcome and finish.

> **Note:** findings that were already fixed as part of this workflow do not
> need an issue — only newly discovered, still-open problems should be
> reported.

Finally, fold in any knowledge-base candidates the coder and the review
proposed: this step is the single writer for `.workflow/helpers/` (see
[Knowledge Base](#knowledge-base-workflowhelpers) below). Prefer writing the
check over writing the entry — if a regression test, PHPStan rule or lint rule
could catch the class of defect, add it instead.

---

## Proof of Work (`.workflow/proof_of_work/`)

Every cycle leaves four kinds of file behind, in
`.workflow/proof_of_work/<issue>-<slug>/`:

| File                  | Written by              | What goes in it                                                              |
| --------------------- | ----------------------- | --------------------------------------------------------------------------- |
| `findings-coder.md`   | the coder, appended     | obstacles, surprises, bugs noticed in passing — including ones outside scope |
| `findings-review.md`  | the review, appended    | one entry per finding: `file:line`, what is wrong, severity, what happened to it |
| `code-decision-<N>.md` | the coder, one per round | the approach taken in round `<N>`, what was rejected, what was uncertain    |
| `review-<N>.md`       | the review, one per round | the review output for round `<N>`                                          |

`<N>` is the round of the inner loop, starting at 1. Six files means three
rounds, and three rounds means something was hard — which is most of what a
reader wants to know at a glance.

The two `findings-*` files are separate because the two roles disagree, and a
shared file turns disagreement into an edit war. Keeping them apart lets the
review say "still present" about something the coder called fixed, with both
statements surviving in the record.

Nothing validates these files. There is no schema, no manifest and no CI gate
— a reader checks them during review, the same way they check the code.
[proof_of_work/README.md](proof_of_work/README.md) explains the why.

---

## Knowledge Base (`.workflow/helpers/`)

A persistent knowledge base so lessons learned carry over to future tasks:

- `faq.md` — recurring pitfalls and their solutions (FFI loading, coverage,
  zero-deps principle). Ids `FAQ-NNN`.
- `decisions.md` — project decisions with rationale (branch from `main`,
  conventional commits with scope, no `composer.lock`, CI matrix). Ids
  `DEC-NNN`.
- `README.md` — entry format, single-writer rule, decay rules.

**Read the index, not the file.** Every file opens with a generated **tag
index** mapping tags to entry ids. A subagent loads the index, picks the tags
matching the files in its diff, and reads only those `###` entries. Reading
300 lines of FAQ for a two-file change is exactly what the index exists to
prevent.

**One writer.** Only the **main session**, at the end of the cycle (step 14),
writes to `.workflow/helpers/`. `coder` and `review` **propose** candidate
entries in their report — title, tags, trigger, one paragraph — and the main
session decides what lands. Two writers produced duplicates, unlabelled
entries and a file that had to be read in full; a subagent that appends to the
knowledge base itself is doing the wrong thing.

**Prefer a gate over an entry.** If a regression test, PHPStan rule or lint
rule could catch the class of defect, add the check. The knowledge base is a
buffer for what cannot be automated yet, not a destination.

Every entry carries single-line front matter (`id`, `date`, `tags`, `trigger`,
`hits`, `status`) in an HTML comment right after its heading.
`php bin/kb-lint.php` validates it, regenerates the tag index (`--fix`), warns
above 300 lines per file and lists `stale` entries. It runs inside
`composer lint`. Full reference: [helpers/README.md](helpers/README.md) and
[bin/README.md](../bin/README.md#kb-lintphp).

---

## Agent Map

Which agent runs at which step. These are **role names, not a harness**: the
workflow assumes you can start a subagent with its own context and give it a
scoped instruction, and assumes nothing else.

| Step  | Agent                          | Role                                                                  |
| ----- | ------------------------------ | -------------------------------------------------------------------- |
| 1     | `delegate`                     | triage open issues, return a ranked shortlist                        |
| 1b    | `scout`                        | fast recon: relevant files, flows, KB tags to load                   |
| 1c    | `context-builder`              | compress the issue + code into a handoff brief                       |
| 2b    | `planner`                      | plan the change before any edit                                       |
| 2c    | `oracle`                       | judgement call on approach when the plan is contested                |
| 3     | `coder` / `coder-high` / `worker` | implement; write `code-decision-<N>.md` and `findings-coder.md`    |
| 4, 6  | `review` / `review-critical`   | code review; write `review-<N>.md` and `findings-review.md`         |
| 11    | `delegate`                     | compress CI logs into actionable failures                            |
| 14    | `reviewer`                     | verify candidate findings before opening GitHub issues              |

**`review-critical` is mandatory**, not a judgement call, when the diff touches
any of:

- `src/Composer/` or `src/ffi/` (binary download / FFI boundary)
- security-relevant code or policy
- more than **200 changed lines**
- a public interface (`EncoderInterface`, `RendererInterface` and friends)

Otherwise `review` is enough.

---

## Quick Reference – Full Cycle

```bash
# 1. Pick an issue — fast path: rank candidates, then let the LLM/user choose
#    php bin/pick-issue.php            # top 5 (no milestone gate in this repo)
#    php bin/pick-issue.php --top=10 --json
#    alternative: delegate full triage to a subagent ("List top 5 impactful…")

# 2. Feature branch — name derived by the helper (type from labels/prefix)
branch="$(bin/gh-branch <issue>)"     # workflow/tooling changes: bin/gh-branch <issue> process
git push -u origin "$branch"
mkdir -p .workflow/proof_of_work/$(printf '%03d' <issue>)-<slug>

# 3. Implementation (worker/coder subagent)
#    subagent: "Implement issue #<n>…"
#    writes code-decision-1.md + findings-coder.md, commits them with the change
#    report must include: files changed, BIGGEST problem, discovered bugs
#    / places to improve (also out of scope)

# 4. Code review (subagent) — reads findings-review.md first, then looks for new issues
#    writes review-1.md + findings-review.md
#    AFTER EVERY ROUND (clean or not): commit the review's files — a clean
#    round has no fix commit to sweep them up, so commit them explicitly:
#    git add .workflow/proof_of_work/<issue>-<slug>/ && git commit -m "docs: record review round <N>"

# 5-6. Fix, answer every finding, re-review (review-2.md, review-3.md, …)
#      a finding that an automated check could have caught: write the check
#      past ~4 rounds, decide instead of iterating — narrow, re-plan, or ask

# 7. Run linters and tests locally
composer lint && composer test

# 8. Update CHANGELOG.md

# 9. Open the pull request — after implementation and local gates (created ready)
gh pr create --title "…(closes #<n>)" --body "…" --base main --assignee @me

# 10-11. CI
gh pr checks --watch
# ... if failures → fix, review, push → wait for CI (repeat)

# 12. Merge
gh pr merge <n> --squash --delete-branch

# 13. Switch back to main
git checkout main && git pull origin main

# 14. Report + offer GitHub issue for discovered problems
#     show: biggest problem(s), discovered bugs / places to improve
#     (read them out of findings-coder.md and findings-review.md)
#     verify each candidate with a review subagent (finding is real?
#     no duplicate on GitHub? use --limit >30 in issue lists)
#     then ask: "Create GitHub issue(s)?" → if yes: gh issue create ...
#     fold any accepted knowledge-base candidates into .workflow/helpers/
```

---

## Subagent Usage Summary

Most steps of this workflow are delegated to subagents to keep the main
session's context lean.

| Step   | Subagent task                                                              | Why delegate                                                          |
| ------ | -------------------------------------------------------------------------- | -------------------------------------------------------------------- |
| 1      | Triage open issues, return ranked shortlist                                | Issue bodies + comments are token-heavy                              |
| 3      | Implement the issue (worker/coder)                                         | Coding context is token-heavy; agent returns structured report (files, biggest problem, discovered bugs) |
| 4, 6   | Code review of the implementation diff, `findings-review.md` first        | Full diff + surrounding code is token-heavy; the review must revisit every open finding before hunting for new ones |
| 14     | Verify candidate findings before creating GitHub issues (read-only)        | GitHub duplicate search (open + closed, `--limit` > 30) plus code verification across several findings is query-heavy |

All subagents have read/write/edit/bash tools and operate on the same
repository (the step-14 verifier is instructed to run read-only). Give each one
a clear, scoped instruction and a defined output format (ranked list with
rationale / numbered findings list with `file:line | description | severity` /
coder report with biggest problem + discovered bugs). The coder and the review
each write their own files under `.workflow/proof_of_work/<issue>-<slug>/` —
the main session does not retype their output into a summary. A report that
only exists in chat is gone the moment the context is compacted.

**Knowledge base:** implementation and review subagents read
`.workflow/helpers/` before starting — the tag index plus the entries matching
the files in the diff — and **propose** candidate entries in their report. They
never append; the main session folds accepted candidates in at step 14 (see
[Knowledge Base](#knowledge-base-workflowhelpers) above).

---

## Notes

- **gh** must be configured and authenticated (`gh auth status`).
- `main` is **not** protected by a ruleset in this repo — but the workflow
  still forbids direct pushes to `main`; always use a PR. The merge decision
  remains the maintainer's own, gated by green CI.
- Keep feature branches short-lived. If a rebase is needed:

  ```bash
  git fetch origin main
  git rebase origin/main
  git push --force-with-lease origin feat/issue-<n>-<slug>
  ```

- Code review via subagent runs locally. `coder`/`coder-high` are granted
  read/write/edit/bash; `review`, `review-critical`, `reviewer` and `scout`
  are granted only read/bash — there is nothing to withhold by instruction,
  they simply cannot write or edit. Give each one clear instructions on what
  to check.
- **Lowering a gate is never an option.** Dropping a coverage floor, disabling
  a linter rule or relaxing a PHPStan level to make a round look clean is
  forbidden — a metric improved by weakening its own check measures nothing.
  Ask the user instead.
- `.workflow/proof_of_work/` carries `export-ignore` (in `.gitattributes`), so
  it is not part of the distributed package.

---

## Differences from the source workflow

This workflow is adapted from `crazy-goat/workerman-bundle`'s `docs/workflow.md`.
Key differences for ScanMePHP:

| Aspect                  | workerman-bundle                | ScanMePHP                                                  |
| ----------------------- | ------------------------------- | --------------------------------------------------------- |
| Default branch          | `master` (PR-protected ruleset) | `main` (no ruleset; workflow still mandates PRs)          |
| Documentation dir       | `docs/` (committed)            | `.workflow/` (`docs/` is in `.gitignore` by choice)       |
| Milestones / release gate | semver milestones, exit 3 gate | none — `pick-issue.php` ranks all open issues             |
| Lint tools              | php-cs-fixer, phpstan, rector in CI | added as dev-deps; **not yet wired into CI** — run `composer lint` locally |
| Coverage gate           | 80% line coverage in CI        | no floor in CI (see `faq.md` FAQ-002)                     |
| Background daemon       | Workerman on ports 8888/9999   | none — no ports to free (`faq.md` FAQ-001)                |
| `composer.lock`         | committed                      | **not committed** (in `.gitignore`, see `decisions.md` DEC-004) |
| Commit scopes           | `(core)`/`(runtime)`/…         | `(encoding)`/`(renderer)`/`(exception)`/`(composer)`/`(ffi)`/`(config)`/`(core)` |
| Zero-runtime-deps       | n/a                            | hard rule — nothing in `require` (`faq.md` FAQ-004)       |
