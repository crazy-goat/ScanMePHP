#!/usr/bin/env bash
# Run all static analysis, linters and formatter checks. --fix applies fixes first.
# Needs composer dependencies, clang-format, shellcheck and hadolint (CI installs them).
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

FIX=0
[ "${1:-}" = "--fix" ] && FIX=1
failed=()

step() {
    local name="$1"; shift
    echo "==> $name"
    "$@" || failed+=("$name")
}

# Own C/C++ sources (clib/ and php-ext/). src/ffi/scanme_qr.h is a stripped header for FFI::cdef,
# not compiled code, so it is left out.
cpp_files() {
    git ls-files -z 'clib/*.cpp' 'clib/*.hpp' 'clib/*.h' 'php-ext/*.c' 'php-ext/*.h'
}

# Every tracked shell script: by extension, plus extensionless files with a shell shebang.
shell_scripts() {
    local file
    while IFS= read -r -d '' file; do
        case "$file" in
            *.sh) printf '%s\0' "$file"; continue ;;
            *.*) continue ;;
        esac
        if [ -f "$file" ] && head -n 1 "$file" 2>/dev/null | grep -aqE '^#!.*[/ ](ba|da|k)?sh( |$)'; then
            printf '%s\0' "$file"
        fi
    done < <(git ls-files -z)
}

if [ "$FIX" = 1 ]; then
    vendor/bin/rector process --no-progress-bar || true
    vendor/bin/php-cs-fixer fix --allow-risky=yes || true  # exits non-zero when it changed files
    cpp_files | xargs -0 -r clang-format -i || true
fi

step "php-cs-fixer" vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes
step "rector" vendor/bin/rector process --dry-run --no-progress-bar
step "phpstan" vendor/bin/phpstan analyse --no-progress
step "clang-format" bash -c "$(declare -f cpp_files); cpp_files | xargs -0 -r clang-format --dry-run --Werror"
step "shellcheck" bash -c "$(declare -f shell_scripts); shell_scripts | xargs -0 -r shellcheck"
step "hadolint" bash -c "git ls-files -z '*Dockerfile*' | xargs -0 -r hadolint"

if [ "${#failed[@]}" -gt 0 ]; then
    echo "Failed: ${failed[*]}" >&2
    exit 1
fi
echo "All checks passed."
