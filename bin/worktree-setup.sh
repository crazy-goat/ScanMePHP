#!/usr/bin/env bash
# Prepare a fresh worktree: Composer dependencies.
# Called by bin/worktree.sh after a new worktree is created.
# No test containers are needed. The C++ library (clib/) and the PHP extension (php-ext/) are
# not built here: the PHP tests fall back to the pure-PHP encoder, and CI builds both. Build
# them only when you change them (see AGENTS.md).
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

composer install --no-interaction --prefer-dist
