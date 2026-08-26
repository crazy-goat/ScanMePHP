#!/usr/bin/env bash
# Assembles crazy-goat/qrcode-ext — the PIE package — out of php-ext/ and clib/.
#
#   bash bin/build-ext-mirror.sh                 assemble into build/ext-mirror and stop
#   bash bin/build-ext-mirror.sh --push v0.5.1   ... and publish it as that tag
#
# Why a second repository: PIE requires an extension's Composer package name to differ from any
# regular package's, and Packagist reads composer.json only at a repository root. ScanMePHP's
# root is already crazy-goat/scanmephp, so the extension cannot be a PIE package from inside
# this repository no matter how the files are arranged.
#
# It is generated, not maintained. php-ext/ and clib/ stay the only places these sources are
# edited; each release overwrites the mirror with one commit and tags it, so the mirror carries
# no history worth preserving and cannot drift. php-ext/composer.json is written for the
# mirror's root — that is why it names qrcode-ext and not scanmephp.
#
# The C++ core is flattened into the mirror root because config.m4 has to find it there:
# PHP_ADD_SOURCES_X splits a source name on the first "." and cannot express "../clib/src/x.cpp"
# at all, so inside this repository configure symlinks the same files into php-ext/ instead.
set -euo pipefail

REPO="crazy-goat/qrcode-ext"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/build/ext-mirror"

push=0
tag=""
for arg in "$@"; do
    case "$arg" in
        --push) push=1 ;;
        v*) tag="$arg" ;;
        *) echo "usage: $0 [--push] [vX.Y.Z]" >&2; exit 2 ;;
    esac
done

# -- assemble ------------------------------------------------------------------------------

rm -rf "$OUT"
mkdir -p "$OUT/simd" "$OUT/tests"

# Listed rather than copied wholesale: php-ext/ also accumulates phpize output, .libs, modules
# and — since configure symlinks the core in — a set of dangling-looking .cpp links, and
# shipping any of that is how a package ends up carrying someone else's object files.
for file in config.m4 composer.json scanme_qr.c native_encoder.c native_encoder.h php_scanme_qr.h; do
    cp "$ROOT/php-ext/$file" "$OUT/$file"
done
cp "$ROOT/php-ext/tests/"*.phpt "$OUT/tests/"

# -L so a symlinked source would be materialised rather than mirrored as a link; the check
# further down refuses the archive outright if one survives.
cp -L "$ROOT/clib/src/"*.cpp "$ROOT/clib/src/"*.hpp "$OUT/"
cp -L "$ROOT/clib/src/simd/"*.hpp "$OUT/simd/"
cp -L "$ROOT/clib/include/scanme_qr.h" "$OUT/scanme_qr.h"
cp "$ROOT/LICENSE" "$OUT/LICENSE"

version="$(sed -n 's/#define PHP_SCANME_QR_VERSION "\([^"]*\)".*/\1/p' "$ROOT/php-ext/php_scanme_qr.h")"
[ -n "$version" ] || { echo "could not read PHP_SCANME_QR_VERSION out of php-ext/php_scanme_qr.h" >&2; exit 1; }

cat > "$OUT/README.md" <<EOF
# qrcode-ext

The native QR encoder behind [ScanMePHP](https://github.com/crazy-goat/ScanMePHP), installable
with [PIE](https://github.com/php/pie). Extension version ${version}.

**This repository is generated.** The sources live in
[crazy-goat/ScanMePHP](https://github.com/crazy-goat/ScanMePHP) under \`php-ext/\` and
\`clib/\`, and every release overwrites this mirror with a single commit — issues and pull
requests belong there.

## Installing

\`\`\`bash
composer require crazy-goat/scanmephp
pie install ${REPO}
\`\`\`

Both halves are needed. The extension exposes a single internal class,
\`CrazyGoat\\ScanMePHP\\NativeEncoderCore\`, and its \`encodeMatrix()\` builds a
\`CrazyGoat\\ScanMePHP\\Matrix\` — so without the library loaded it can only throw. The library
in turn detects the extension and routes \`NativeEncoder\` through it automatically; with
neither the extension nor an FFI binary present it falls back to the pure-PHP encoder, so
installation is an optimisation and never a requirement.

Building needs a C++20 compiler (GCC 10+ / Clang 12+) and takes a few seconds. There is nothing
else to install: the C++ core is compiled into the extension rather than linked against a
separate shared library.

## What you get

The encoder runs about 10× faster than the pure-PHP path. On x86-64 the mask-penalty pass is
built three times over — a portable kernel plus AVX2 and AVX-512 variants — and the right one
is chosen at load time from the CPU's own feature bits, so one binary stays correct on any
machine that can run it. arm64 gets the portable kernel, which the compiler vectorises to NEON.

Prebuilt binaries for the common platforms are attached to every
[ScanMePHP release](https://github.com/crazy-goat/ScanMePHP/releases) if you would rather not
compile at all.

MIT.
EOF

echo "assembled $OUT (extension version $version)"
ls -1 "$OUT"

for required in config.m4 composer.json scanme_qr.c encoder.cpp mask_kernel_avx512.cpp scanme_qr.h LICENSE README.md; do
    test -s "$OUT/$required" || { echo "mirror is missing $required" >&2; exit 1; }
done

# A symlink here would publish a package that unpacks to a dangling path on someone else's disk.
if find "$OUT" -type l | grep -q .; then
    echo "mirror contains symlinks:" >&2
    find "$OUT" -type l >&2
    exit 1
fi

php -r 'exit(json_decode(file_get_contents($argv[1])) === null ? 1 : 0);' "$OUT/composer.json" \
    || { echo "composer.json is not valid JSON" >&2; exit 1; }

# The one thing that cannot be caught by inspection: whether the flattened layout still builds.
# config.m4 takes a different branch here than it does inside this repository.
if command -v phpize >/dev/null 2>&1; then
    echo "building the assembled package..."
    ( cd "$OUT" && phpize >/dev/null && ./configure --silent >/dev/null && make -j"$(getconf _NPROCESSORS_ONLN)" >/dev/null ) \
        || { echo "the assembled package does not build" >&2; exit 1; }
    ( cd "$OUT" && make -s test TESTS=tests NO_INTERACTION=1 >/dev/null ) \
        || { echo "the assembled package fails its own tests" >&2; exit 1; }
    echo "build and tests ok; cleaning up"
    ( cd "$OUT" && make -s distclean >/dev/null 2>&1 || true )
    rm -rf "$OUT/.libs" "$OUT/modules" "$OUT/build" "$OUT/autom4te.cache" "$OUT/tests/"*.php \
           "$OUT/tests/"*.diff "$OUT/tests/"*.out "$OUT/tests/"*.exp "$OUT/tests/"*.log
    rm -f "$OUT/configure" "$OUT/configure.ac" "$OUT/config.h.in" "$OUT/config.h" "$OUT/config.log" \
          "$OUT/config.status" "$OUT/config.nice" "$OUT/libtool" "$OUT/run-tests.php" \
          "$OUT/acinclude.m4" "$OUT/aclocal.m4" "$OUT/Makefile" "$OUT/Makefile."* \
          "$OUT/"*.lo "$OUT/"*.la "$OUT/"*.dep "$OUT/"*.o
else
    echo "phpize not found; skipping the build check" >&2
fi

if [ "$push" -eq 0 ]; then
    echo "not pushing (pass --push v$version to publish)"
    exit 0
fi

# -- publish -------------------------------------------------------------------------------

[ -n "$tag" ] || { echo "--push needs a tag, e.g. --push v$version" >&2; exit 2; }

if [ "${tag#v}" != "$version" ]; then
    echo "tag $tag does not match PHP_SCANME_QR_VERSION $version" >&2
    exit 1
fi

git -C "$ROOT" diff --quiet && git -C "$ROOT" diff --cached --quiet \
    || { echo "the working tree is dirty; the mirror must match a committed state" >&2; exit 1; }

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

gh repo clone "$REPO" "$work/mirror" -- --quiet
cd "$work/mirror"

# Explicit, because the first run clones a repository with no commits at all: there the branch
# is unborn and its name comes from whatever init.defaultBranch the workstation happens to have.
git checkout -qB main

# Everything except .git: the mirror is a snapshot, so a file dropped from php-ext/ or clib/ has
# to disappear here too rather than linger from the previous release.
find . -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
cp -R "$OUT/." .

git add -A
source_commit="$(git -C "$ROOT" rev-parse --short HEAD)"
if git diff --cached --quiet; then
    echo "mirror is already up to date; tagging only"
else
    git commit -q -m "Mirror ScanMePHP $tag ($source_commit)"
fi

git tag -a "$tag" -m "qrcode-ext $tag, generated from ScanMePHP $source_commit"
git push -q origin main
git push -q origin "$tag"

echo "pushed $REPO $tag"
