# Contributing

Thanks for contributing to this project.

The full process (issue, worktree, review, pull request, merge) is in
[docs/workflow.md](docs/workflow.md); releases are described in
[docs/release-workflow.md](docs/release-workflow.md). Build, test and lint commands are in
[AGENTS.md](AGENTS.md).

## Setup

1. Fork and clone this repository.
2. Run `composer install`.
3. Run `composer test` and `bin/lint.sh` before opening a pull request. `bin/lint.sh` needs
   `clang-format`, `shellcheck` and `hadolint`; `bin/lint.sh --fix` applies the fixes.

## Pull requests

- Keep changes focused. One issue, one branch, one pull request.
- Use a Conventional Commit for the title (`fix: handle empty data (#42)`) and put
  `Closes #<N>` in the description.
- Add or update tests, and update `CHANGELOG.md` under `[Unreleased]`.
- Write everything in English: code, comments, commits, docs.
- The required check is `ci-ok`. The pull request is squash merged when it is green.

## Conduct

Be respectful and constructive in reviews and discussions.
