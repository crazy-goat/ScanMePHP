# Coder findings — process-001 add-agent-workflow

Appended across the cycle. Obstacles, surprises, and bugs/weak spots noticed
in passing — including ones outside this change's scope.

## Obstacles / surprises

- `docs/` is **intentionally gitignored** in this repo (commit `b6652e1`
  "chore: remove docs/ from repository and add to .gitignore"). The
  workerman-bundle workflow stores everything under `docs/`, so it had to be
  adapted to `.workflow/` instead. Not obvious until you grep `.gitignore`.
- `composer.lock` is gitignored and the tracked `composer.json` had a latent
  conflict: `phpunit/phpunit: ^10.0` vs `brianium/paratest: ^7.0` (paratest 7
  requires phpunit 11/12/13). The lock file on disk had phpunit 13.3.1 which
  satisfied neither side cleanly. Resolving required dropping PHP 8.1 and
  bumping phpunit to `^11.5` + paratest `^7.6` (both support PHP 8.2+).
- `setup-php` CI uses `coverage: none` and there is **no coverage floor** —
  the workerman-bundle 80% gate does not apply here.
- There is **no background daemon** in ScanMePHP (unlike workerman). The
  workflow's "free ports 8888/9999" step is irrelevant; documented in FAQ-001.
- `release-build.yml` still builds precompiled `.so` binaries for PHP 8.1.
  That is a separate ABI concern (a compiled extension links against the 8.1
  ABI) and is **intentionally left as-is** even though the library code now
  requires PHP 8.2+.

## Discovered bugs / weak spots (in passing, outside scope)

- `src/NativeEncoder.php` uses a conditional `if/else` class declaration so
  the optional `scanmeqr` C extension can swap in a `NativeEncoderCore` base.
  PHPStan sees both branches at once and reports `class.notFound`/
  `class.noParent`/`arguments.count` that cannot both occur at runtime.
  Excluded from analysis (DEC-005); a future refactor could split into two
  files + a factory, but that is out of scope here.
- `tests/QrReferenceTest.php:47` triggers a deprecation: `fgetcsv()` called
  without the `$escape` parameter. Real, but out of scope for this process
  change — candidate for a separate `code-quality` issue.
- `src/FfiEncoder.php` accesses dynamic C struct properties (`$out->modules`,
  `$out->size`, `$out->version`) and calls C functions via `FFI::cdef`.
  PHPStan cannot resolve these. Excluded from analysis (DEC-005).
- 1821 PHPUnit errors + 1 failure locally are **entirely** the unbuilt C++
  FFI library on macOS (`dlopen ... slice is not valid mach-o file`). Identical
  counts before and after this change — no regression. The CI matrix builds
  `clib/` so this is a local-only environment issue.
