#!/bin/bash
# Builder::isBuildAvailable must not probe PATH with Unix-only `which` on Windows.
# This Mac has no Windows; the red case is a PATH fixture (cmake present, which absent).
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
builder="$root/src/Builder.php"

if ! grep -q "PHP_OS_FAMILY === 'Windows'" "$builder"; then
  echo "Builder.php missing Windows OS family dispatch" >&2
  exit 1
fi
if ! grep -q "where " "$builder"; then
  echo "Builder.php missing Windows where lookup" >&2
  exit 1
fi
if grep -qE "shell_exec\('which " "$builder"; then
  echo "Builder.php still uses unconditional which via shell_exec" >&2
  exit 1
fi
if ! grep -q "2>NUL" "$builder"; then
  echo "Builder.php missing Windows NUL stderr redirect" >&2
  exit 1
fi

work=$(mktemp -d)
mkdir -p "$work/bin"
cat >"$work/bin/cmake" <<'EOF'
#!/bin/sh
printf '%s\n' "$0"
EOF
chmod +x "$work/bin/cmake"

# Red: PATH has cmake but no which binary (Windows CMD-like). External which fails.
set +e
red_out=$(env -i PATH="$work/bin" /bin/bash --noprofile --norc -c 'which cmake 2>/dev/null')
red=$?
set -e
if [ "$red" -eq 0 ] && [ -n "$red_out" ]; then
  echo "which unexpectedly resolved cmake with which missing from PATH" >&2
  exit 1
fi

# Green: Windows-style where stand-in finds cmake on the same PATH.
cat >"$work/bin/where" <<'EOF'
#!/bin/sh
target=$1
IFS=:
for d in $PATH; do
  if [ -n "$d" ] && [ -x "$d/$target" ]; then
    printf '%s\n' "$d/$target"
    exit 0
  fi
done
exit 1
EOF
chmod +x "$work/bin/where"

# Use /dev/null here: bash on this Mac would otherwise create a file named NUL.
found=$(env -i PATH="$work/bin" /bin/bash --noprofile --norc -c 'where cmake 2>/dev/null')
printf '%s\n' "$found" | grep -q '/cmake$'

echo "windows which-lookup fixture ok (which missing exit $red, where found cmake)"
rm -rf "$work"
