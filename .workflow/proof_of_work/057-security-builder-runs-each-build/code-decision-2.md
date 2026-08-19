# Code Decision — Round 2, issue #57

Round 2 addressed the four findings from review round 1
(`findings-review.md`). No re-planning; all four were small, in-scope
adjustments.

## Decisions

### F-1 — CHANGELOG entry (medium → fixed)
Added a `### Fixed` entry under `## [Unreleased]` describing both halves of
the fix (single `exec()` per command; no `2>&1`; sanitised `BuildException`).
This is also workflow step 8; doing it as part of the fix commit keeps the
branch self-contained before the PR is opened.

### F-2 — sanitised-message test coverage (low → fixed)
Added `testBuildExceptionFactoriesAreSanitised` as a PHPUnit data provider
exercising all four `BuildException` factories (`toolsNotAvailable`,
`cmakeFailed`, `buildFailed`, `libraryNotFound`). Each case asserts the
message contains the expected fragment, does **not** contain `2>&1`, does
**not** contain an absolute path (`/Users/`, `C:\`, or a leading `/X` / `\X:`),
and that the exception extends `\RuntimeException`. This turns the
security invariant from "guarded on 1 of 4 paths" into "guarded on all 4"
and fails loudly if a future refactor re-appends raw output. This follows the
workflow rule "write the check over reporting the finding again".

### F-3 — tautological `basename()` (nit → deliberately not fixed)
`basename($buildPath)` is the security control that strips the absolute path
from `libraryNotFound()`. The fact that it always yields `'build'` is a
harmless consequence of the build directory always being `<root>/clib/build`.
Removing the directory name would make the message less useful with no
security gain; reintroducing a fuller path would re-open exactly the leak
F-2 now guards. Per DEC-002 (smallest correct change) the message stays as-is.

### F-4 — dead `$cmakeOutput` / `$makeOutput` (nit → fixed)
Removed the by-reference `&$output` parameter from `runCommand()` and the
`@param-out string` PHPDoc hack that existed only to satisfy PHPStan's
`parameterByRef.unusedType` rule. `runCommand()` now returns only the exit
code; the two dead locals and the unused by-ref seam are gone. The "future
structured-logging seam" the coder mentioned is not lost — adding a return
or a logger later is a trivial change to one private method.

## What was rejected
- Reintroducing captured output (e.g. for a future log file) now, without a
  caller, would re-create dead code. Rejected; add it when there is a logger.
- Widening `libraryNotFound()` to include the full path for "debuggability".
  Rejected — that is the leak this PR closes.

## Uncertainty
None. All four findings had clear, low-risk resolutions.
