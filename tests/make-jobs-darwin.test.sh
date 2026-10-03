#!/bin/bash
# Builder.php must not ask make for $(nproc). macOS does not ship nproc.
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
builder="$root/src/Builder.php"

# The single quotes are the point: this is a source search, not a command.
# shellcheck disable=SC2016
if grep -q '$(nproc)' "$builder"; then
  echo "Builder.php still calls nproc" >&2
  exit 1
fi

work=$(mktemp -d)
set +e
nproc >"$work/red.out" 2>"$work/red.err"
red=$?
set -e
if [ "$red" -eq 0 ]; then
  echo "nproc unexpectedly succeeded" >&2
  exit 1
fi
if ! grep -q "command not found" "$work/red.err"; then
  echo "expected nproc command not found" >&2
  cat "$work/red.err" >&2
  exit 1
fi

jobs=$(sysctl -n hw.ncpu 2>/dev/null || getconf _NPROCESSORS_ONLN 2>/dev/null || echo 1)
printf '%s\n' "$jobs" | grep -Eq '^[1-9][0-9]*$'
echo "make jobs darwin ok (nproc exit $red, jobs $jobs)"
rm -rf "$work"
