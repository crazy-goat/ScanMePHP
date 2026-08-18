# Review — round 2 (review-critical)

Issue: #48 — native binaries downloaded by the Composer plugin without
checksum/signature verification → RCE on release-channel compromise.
Branch: `fix/issue-48-security-native-binaries-are-downloaded`
Commit under review: `97a76a7` ("fix(composer): surface checksum refusal
clearly + single-v strip (review round 1)")
Reviewer: review-critical agent
Date: 2026-08-18

## Verdict: APPROVE

The round-1 fixes are correctly applied and verified. The fail-closed
guarantee remains airtight — the fix only changed catch-block message
routing and string formatting, never the download/verification logic in
`BinaryDownloader::download()`. No gates were lowered (PHPStan baseline,
CI, phpunit config, composer.json all untouched in the fix commit). All
tests pass (5396 tests, 11509 assertions). Lint passes clean.

One new low-severity finding: the message-comparison approach used to
distinguish checksum-refusal from other DownloadExceptions is correct
today but fragile under future refactoring. This is a maintainability
concern, not a security issue — the worst case is a UX regression (wrong
message shown), never a bypass of fail-closed.

---

## Verification of round-1 finding dispositions

### Finding 1 (medium — misleading framing) → FIXED, verified correct

Both `installExtensionBinary()` and `installFfiBinary()` now have a
`catch (DownloadException $e)` block before the generic
`catch (\Exception $e)`. The block branches on:

```php
if ($e->getMessage() === DownloadException::checksumMissing($binaryName)->getMessage())
```

Traced the message comparison: `$binaryName` is an immutable local string
that doesn't change between the download call and the catch, and
`checksumMissing()` is a pure function (`sprintf` with a fixed format
string) → both calls produce byte-identical messages. Catch ordering is
correct (DownloadException before \Exception, PHP first-match).
Strengthened PluginTest assertion ('refused' + 'extra.scanmephp.checksums')
passes with 4 assertions. ✓

### Finding 2 (low — existing binaries not re-verified) → STILL OPEN (deferred), acceptable

The round-1 fix commit did not touch the `file_exists` early-return paths.
The deferral is correct: transitional migration concern for existing
installs that had binaries from pre-fix plugin versions. New installs are
fully protected by fail-closed. An attacker with filesystem write access to
`vendor/` already has RCE regardless. Acceptable for this PR — should be
tracked as a follow-up issue. ✓

### Finding 3 (nit — ltrim strips all leading 'v') → FIXED, verified equivalent

Single-v strip (`str_starts_with` + `substr`) is identical to `ltrim` for
all valid inputs. The version reaching `ChecksumManager` is validated by
`BinaryDownloader`'s constructor regex `/^v?\d+\.\d+\.\d+$/` (at most one
'v'), so the divergent behavior for `'vv0.4.4'`-style inputs is
unreachable. No edge case broken. ✓

---

## New findings from round 2

### New Finding A — LOW: Message comparison via string equality is fragile

**File:** `src/Composer/Plugin.php:176` (extension), `Plugin.php:234` (FFI)

The checksum-refusal detection relies on string equality of exception
messages. Works correctly today (traced above) but semantically fragile:
if a future refactor adds context to `checksumMissing()` (version, URL,
timestamp) or changes the format string, the comparison silently breaks
and the user sees "download failed" instead of the actionable "refused —
add extra.scanmephp.checksums" message. Not a security issue — the
exception is caught either way, fail-closed holds.

**Smallest safe fix direction:** exception code constant
(`CODE_CHECKSUM_MISSING`), dedicated subclass `ChecksumMissingException`,
or `isChecksumMissing()` method. Follow-up, not a blocker.

### New Finding B — NIT: Duplicated "download failed" message in both catch branches

**File:** `src/Composer/Plugin.php:182-184/186-189`, `240-241/243-245`

The `catch (DownloadException $e)` else branch and the
`catch (\Exception $e)` branch produce identical output in both methods.
Intentional, but diverge silently if one is changed. Not worth the
complexity to deduplicate unless Finding A is fixed.

---

## High-risk areas re-confirmed clean

- **Fail-closed guarantee** — `BinaryDownloader::download()` unchanged;
  fix only modified catch blocks and string formatting; no path opened.
- **No gate lowered** — `git diff 25325b9..97a76a7 -- phpstan-baseline.neon
  phpstan.neon.dist .github/ phpunit.xml composer.json` → empty.
- **Catch ordering** — DownloadException before \Exception, PHP first-match. ✓
- **Tests** — 5396 tests, 11509 assertions, OK; PluginTest 4 assertions
  pass.
- **Lint** — php-cs-fixer 0 fixes, phpstan no errors, rector OK, kb-lint ok.
- **Zero runtime deps** — composer.json require unchanged; new import is a
  project-namespace class. ✓ (FAQ-004)
- **Commit message** — `fix(composer): ...` follows DEC-002; main commit
  carries `(closes #48)`.
- **CHANGELOG** — existing #48 entry covers the overall fix; refinements
  don't need a separate entry.
