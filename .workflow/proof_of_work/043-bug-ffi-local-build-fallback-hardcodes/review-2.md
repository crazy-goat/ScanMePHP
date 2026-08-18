# Round 2 Review — Issue #43 (`fix/issue-43-bug-ffi-local-build-fallback-hardcodes`)

Scope: verify the round-1 fixes hold and that the `localBuildPath()` extraction
introduced no new issues. Reviewed the working-tree diff against `main` (changes
are uncommitted in the working tree, branch is even with `origin/main`).

## Existing findings — status table

| # | Severity | Finding | Round-1 status | Round-2 verdict | Evidence |
|---|----------|---------|----------------|-----------------|----------|
| 1 | low  | `PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'` suffix logic duplicated in 3 places (`src/FfiEncoder.php`, `tests/FfiEncoderTest.php`, `tests/QrReferenceTest.php`) | fixed | **Still fixed.** Single source of truth is now `FfiEncoder::localBuildPath()` (`src/FfiEncoder.php:104-109`). `resolveLibraryPath()` calls `self::localBuildPath()` (line 92); `FfiEncoderTest::setUpBeforeClass` calls `FfiEncoder::localBuildPath()` (line 18); `QrReferenceTest::testFfiEncoderMatchesReference` calls `FfiEncoder::localBuildPath()` (line 82). `grep -rn "PHP_OS_FAMILY === 'Darwin'" src/ tests/` returns only the one definition in `FfiEncoder.php`. | `git diff main...HEAD`, `grep` |
| 2 | nit  | Unrelated example asset files modified in working tree | fixed | **Still fixed.** `git status --short` lists only `CHANGELOG.md`, `src/FfiEncoder.php`, `tests/FfiEncoderTest.php`, `tests/QrReferenceTest.php` plus untracked proof-of-work/`review-reports/` dirs. No `examples/generated-assets/*` changes. | `git status --short` |

## New findings introduced by the round-1 fix

None.

### Checks performed on the `localBuildPath()` extraction

1. **Method placement / visibility** — `public static`, declared in `FfiEncoder`
   alongside the other static helpers (`isAvailable`, `resolveLibraryPath`).
   Placement is consistent with the surrounding code; `public` is required so
   the two test classes can call it. ✔
2. **Docblock accuracy** — States the suffix is derived from `PHP_OS_FAMILY`
   and explains why CMake yields the platform-default name. Verified against
   `clib/CMakeLists.txt`: `add_library(scanme_qr SHARED ...)` with
   `set_target_properties` setting only visibility/PIC — no `SUFFIX` override,
   so CMake emits `.dylib` on Darwin and `.so` elsewhere. The docblock's claim
   ("no override") is accurate. ✔
3. **Tests still exercise FFI (not mask skip)** — Ran
   `vendor/bin/phpunit --filter "FfiEncoder|QrReference|ExtensionNameConsistency"`:
   5330 tests, 0 failures, 0 skipped on this macOS host (the `.dylib` resolves).
   `FfiEncoderTest::setUp` skips only when `isAvailable()` is false; on this
   machine the library is available so the suite genuinely invokes the native
   encoder. `testLibraryNotFoundThrows` still uses a `.so` literal in a
   deliberately-nonexistent path — that is a negative-test fixture, not a
   local-build path, so it is correct and out of scope. ✔
4. **No `.so` literal for the local build path remains** —
   `grep -rn "libscanme_qr\.so\|libscanme_qr\.dylib" src/ tests/` returns only:
   - `src/Composer/Plugin.php`, `src/PlatformDetector.php` — release-asset
     naming (per-platform filenames, intentionally distinct from the local
     build path).
   - `src/Builder.php:90-91` — `findBuiltLibrary()` glob patterns listing both
     `.so` and `.dylib` (and `.dll`) as candidates; this is a discovery scan,
     not a hardcoded local-build path, and correctly covers all platforms.
   - `tests/*Test.php` checksum/downloader/platform fixtures — release-asset
     names, not local build paths.
   - `tests/FfiEncoderTest.php:107` — negative-test nonexistent path (above).
   No `'/clib/build/libscanme_qr.so'` literal remains anywhere. ✔
5. **PSR-12 / types** — `composer lint` (php-cs-fixer + Rector + kb-lint) clean:
   "No errors", "Rector is done!", "kb-lint: ok (0 warning(s))". Return type
   `string` declared, `declare(strict_types=1)` present. ✔
6. **Contract test coverage** — `ExtensionNameConsistencyTest::testFfiLibraryPathResolutionIsCentralized`
   pins that `src/QRCode.php` and `src/NativeEncoder.php` route through
   `resolveLibraryPath()` and do not contain the old `'/clib/build/libscanme_qr.so'`
   literal. The extraction did not weaken this guard. The test does not pin
   `FfiEncoder.php` itself or the test entry points, but the single-source
   method makes divergence impossible by construction (finding 1's resolution). ✔

## Helper tag-index cross-check (read-only)

- `ffi` tag → `faq.md` FAQ-001 (FFI load failures), FAQ-003 (ext-ffi optional
  in CI), FAQ-005 (PHPStan FFI baseline); `decisions.md` has no `ffi`-tagged
  entry. Candidate new entry (proposed, not written): a FAQ note that the local
  CMake build suffix is platform-derived via `FfiEncoder::localBuildPath()` and
  must not be re-hardcoded — triggers on "libscanme_qr.so not found on macOS".
- `phpstan` tag → FAQ-005 only. No new baseline entries needed; lint is clean.
- `commits` tag → `decisions.md` DEC-002 (Conventional Commits with scope). The
  CHANGELOG entry for #43 is present under `[Unreleased]` and follows
  Keep-a-Changelog. Candidate commit subject (proposed):
  `fix(ffi): derive local build library suffix from PHP_OS_FAMILY (#43)`.

## Verdict

**Clean.** Both round-1 findings remain fixed. The `localBuildPath()`
extraction is well-placed, accurately documented, and introduces no new
correctness, typing, or contract issues. Full suite (5330 relevant tests) and
`composer lint` are green. No new findings to record.
