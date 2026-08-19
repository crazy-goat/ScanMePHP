# Code Decision #1 — Build each command once and stop leaking stderr (#57)

## Approach taken

Replaced the double-execution pattern in `src/Builder::build()` with a single
`exec()` per command that yields both stdout (via the `$output` array
parameter) and the exit code (via the `$result_code` parameter). Extracted a
private `runCommand()` helper so cmake and make share one implementation and
cannot drift apart again.

Removed `2>&1` from both command strings so stderr is no longer merged into
the captured output. The raw output (which previously included compiler
diagnostics, absolute local paths, and environment details) is no longer
concatenated into exception messages. Instead, a new `BuildException`
(`src/Exception/BuildException.php`) with static factory methods surfaces only
a static, sanitised message containing the exit code — following the project
convention used by `DownloadException`, `FileWriteException`, etc.

### Why `exec()` over `proc_open()`

I chose `exec()` for the smallest correct change:

- `exec()` already gives both the output array and the exit code in a single
  invocation, which directly fixes the "runs twice" bug with zero new
  abstractions.
- Removing `2>&1` means stderr goes to the PHP process's stderr (the
  terminal/CI log) instead of being captured into a variable. The issue asks
  to "log it instead" — directing it to the process stderr IS logging for a
  CLI build step (the composer install script already echoes to the console).
  No raw stderr is forwarded to the user via exceptions.
- `proc_open()` would give full control over separate stdout/stderr pipes,
  but it adds significant complexity (pipe management, stream reading, dead
  -lock avoidance when reading both pipes) for no additional benefit here,
  since we deliberately do NOT want to capture stderr into a variable at all.

### What I rejected

1. **`proc_open()` with separate stderr pipe** — rejected for the complexity
   vs. benefit tradeoff above. If a future requirement needs to *capture*
   stderr (e.g. write it to a structured log file), `proc_open` would be the
   right upgrade and `runCommand()` is the single place to change.
2. **Keeping `shell_exec()` + `exec()` but just removing the duplicate** —
   rejected because `shell_exec()` returns the full output as a single string
   but does NOT return an exit code, so you'd still need a second call. `exec()`
   alone does both.
3. **String-matching on exception messages in callers** — the caller
   (`InstallScript.php`) catches `\Exception` generically and echoes
   `$e->getMessage()`, so switching from `\RuntimeException` to
   `BuildException` (which extends `\RuntimeException`) is fully backward
   compatible. No caller changes needed.

### What I was unsure about

- **Whether stderr should be captured for structured logging.** The issue says
  "log it instead." I interpreted "log" as "don't put it in the exception
  message; let it go to the process's stderr." There is no PSR-3 logger in this
  zero-dependency project, so wiring one in would widen scope. If the
  maintainer wants stderr captured to a file, that's a follow-up — the
  `runCommand()` helper is the single seam for that change.
- **The `@param-out` PHPDoc tag.** PHPStan level 4 flagged the `?string`
  by-reference parameter as never being assigned `null`. I narrowed the
  out-type with `@param-out string` rather than changing the signature to
  `string &$output` (which would require callers to pre-initialise and would
  be a wider signature change). The `@param-out` tag is the PHPStan-recommended
  way to express "the parameter is typed as nullable on input but always
  string after the call."

## Files changed

- `src/Builder.php` — single `exec()` per command via `runCommand()`, removed
  `2>&1`, use `BuildException` factories instead of raw `RuntimeException`
  with concatenated output.
- `src/Exception/BuildException.php` — new exception with static factory
  methods (`toolsNotAvailable`, `cmakeFailed`, `buildFailed`, `libraryNotFound`).
- `tests/BuilderTest.php` — added two tests: exception type/message on
  unavailable tools, and message does not leak the temp path or `2>&1`.
