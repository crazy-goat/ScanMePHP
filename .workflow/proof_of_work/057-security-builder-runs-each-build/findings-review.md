# Findings (review) — issue #57

Round 1 review by review-critical. Verdict: APPROVE-WITH-NITS.
No blockers. Four findings below (1 medium, 1 low, 2 nit).

| # | file:line | what is wrong | severity | status | automated check that could catch it |
|---|-----------|---------------|----------|--------|-------------------------------------|
| F-1 | CHANGELOG.md (missing change) | No `## [Unreleased]` → `### Fixed` entry for this security bug fix, violating AGENTS.md "every PR that fixes bugs must have a CHANGELOG entry". | medium | **fixed, round 2** — added `### Fixed` entry under `## [Unreleased]` referencing #57 | CI/lint guard: diff touching `src/` must also touch `CHANGELOG.md` under `## [Unreleased]` |
| F-2 | tests/BuilderTest.php (new tests) | Security-invariant test ("message does not leak output") covers only the `toolsNotAvailable()` path; `cmakeFailed()`, `buildFailed()`, `libraryNotFound()` factories have no test asserting sanitised messages. A future refactor could re-append raw output with no test failing. | low | **fixed, round 2** — added `testBuildExceptionFactoriesAreSanitised` dataProvider covering all four factories, asserting no `2>&1`/absolute-path leak and `instanceof \RuntimeException` | PHPUnit: unit test calling all four factories, asserting message format + no path/`2>&1`/output leak (added) |
| F-3 | src/Exception/BuildException.php:26 | `basename($buildPath)` always yields `'build'` (buildPath is always `<root>/clib/build`); message is tautological "… in build directory: build". No security leak (verified). | nit | **deliberately not fixed, round 2** — `basename()` is the security control (strips the absolute path); the tautological `build` is a harmless consequence. Removing the directory name would make the message less useful without any security gain, and reintroducing a fuller path would re-open the leak F-2 guards. Citing DEC-002: smallest correct change. | — (cosmetic) |
| F-4 | src/Builder.php:57,69 | `$cmakeOutput` / `$makeOutput` written by `runCommand()` but never read (dead). Already self-reported by coder (coder Finding 3). | nit | **fixed, round 2** — dropped the by-ref `&$output` parameter and the `@param-out` PHPDoc hack; `runCommand()` now returns only the exit code and the dead locals are gone | — (PHPStan level 4 did not flag; acceptable as future-logging seam) |

## Notes

- High-risk areas verified clean: stderr leak (empirically), double-execution
  (diff), backward-compat (`BuildException extends \RuntimeException`; sole
  caller `InstallScript.php` catches `\Exception`, no string-equality on build
  messages), namespace/convention, zero-deps, command injection/path traversal
  (`escapeshellarg` + internal-only `$buildPath`), `@param-out string`
  soundness (PHPStan-recommended, `implode` always returns string).
- Out-of-scope pre-existing issues already noted by coder: `which` on Windows,
  `mkdir` return value, `fgetcsv` PHP 8.5 deprecation, bare `RuntimeException`
  in `InstallScript::getPackageVersion()`. Not re-counted as new findings.
- Candidate KB entries proposed (see review-1.md § Candidate knowledge-base
  entries): "Build commands must not merge stderr into captured output"
  (tags: security, ffi, exception) and "BuildException factories are the only
  sanctioned throw from Builder" (tags: exception, ffi).
