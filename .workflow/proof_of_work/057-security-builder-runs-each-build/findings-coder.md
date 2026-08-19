# Findings (coder) — issue #57

## Obstacles / surprises

- **No `clib/` build was needed to validate.** The Builder tests exercise the
  `isBuildAvailable() === false` path (no clib dir → early throw), so a full
  `cmake && make` toolchain was not required to test the core fix. The
  error-path tests verify the new `BuildException` type and sanitised message.
  The happy path (actual cmake/make run) is exercised by CI's
  `cd clib && cmake -B build -S . && cmake --build build -j` step.
- **PHPStan level 4 catches by-reference nullable parameter out-types.** The
  `?string &$output` parameter triggered `parameterByRef.unusedType` because
  `runCommand()` always assigns a `string`. Resolved with a `@param-out string`
  PHPDoc tag — the recommended PHPStan approach.

## Discovered bugs / places to improve (outside this issue's scope)

### Finding 1 — `isBuildAvailable()` uses `which`, unavailable on some platforms
- **File:** `src/Builder.php:22-29`
- **Issue:** `shell_exec('which cmake 2>/dev/null')` relies on the `which`
  utility, which does not exist on Windows (and some minimal containers). On
  Windows the method always returns `false` even if cmake is on `PATH`.
- **Suggested fix:** Use a cross-platform check, e.g.
  `shell_exec('where cmake 2>NUL')` on Windows or check `exec('cmake --version', $_, $code) === 0`.

### Finding 2 — `mkdir()` return value and permissions ignored
- **File:** `src/Builder.php:49`
- **Issue:** `mkdir($buildPath, 0755, true)` return value is not checked. If
  the directory cannot be created (permissions, disk full), the subsequent
  `cd` in the shell command fails and the error surfaces as a confusing cmake
  failure rather than a clear "cannot create build directory" error.
- **Suggested fix:** Check the return value and throw
  `BuildException::cannotCreateBuildDir($buildPath)` on failure.

### Finding 3 — `$cmakeOutput` / `$makeOutput` captured but never used
- **File:** `src/Builder.php:62,72`
- **Issue:** After the refactor, `runCommand()` fills `$cmakeOutput` and
  `$makeOutput` but these variables are never read (the exception messages no
  longer include them). This is intentional (we don't want to leak output),
  but the variables are now dead. A linter might flag them.
- **Suggested fix:** If stdout is genuinely not needed, drop the `$output`
  parameter on success-path calls. However, keeping it allows future
  structured logging without changing the signature. Low priority.

### Finding 4 — `fgetcsv()` deprecation in QrReferenceTest (PHP 8.5)
- **File:** `tests/QrReferenceTest.php:47`
- **Issue:** PHP 8.5 emits a deprecation: the `$escape` parameter of
  `fgetcsv()` must be provided explicitly. This produces 8 deprecation
  notices during `composer test` and will break on future PHP versions.
- **Suggested fix:** Add the explicit `$escape` parameter:
  `fgetcsv($handle, 0, ',', '"', '\\')` (or `''` as appropriate).

### Finding 5 — `getBinaryVersion()` in InstallScript throws bare `RuntimeException`
- **File:** `src/Composer/InstallScript.php` (getBinaryVersion method)
- **Issue:** Throws `new \RuntimeException('Cannot determine package version...')`
  directly instead of using a custom exception factory, inconsistent with the
  project convention of static factory methods on dedicated exception classes.
- **Suggested fix:** Create a dedicated exception or reuse an existing one with
  a factory method. Low priority; out of scope for #57.
