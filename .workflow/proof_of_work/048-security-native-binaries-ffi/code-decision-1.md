# Code decision — round 1

Issue: #48 — Composer plugin downloads native binaries (`.so`/`.dylib`/`.dll`)
from GitHub Releases without any checksum or signature verification → any
compromise of the release channel becomes RCE in every consuming project.
Branch: `fix/issue-48-security-native-binaries-are-downloaded`

## The trust-anchor decision: where do checksums live? (projectRoot)

`ChecksumManager` reads `extra.scanmephp.checksums` from a single
`composer.json` identified by `projectRoot`. The security value of the whole
fix depends on which file that is.

**Decision: the ROOT project's `composer.json` (the consumer's).**

- A checksum is only a protection against release-channel compromise if the
  attacker cannot rewrite it together with the release asset. A checksum
  shipped *inside the package itself* (e.g. the package's own
  `composer.json` in `vendor/crazy-goat/scanmephp/`) is rewritten by the same
  repo/org compromise that swapped the binary — it protects nothing.
- The consumer's root `composer.json` is controlled by the project owner.
  Pinning the SHA-256 there makes the consumer the trust anchor; the plugin
  then refuses anything that does not match the pin.
- The "self-installed" case (this repo as its own root project) falls out
  naturally: the root `composer.json` IS the package's own, so
  `extra.scanmephp.checksums` added there governs downloads.

**How the Plugin resolves the root:** `$this->composer->getConfig()
->get('vendor-dir')` returns an absolute path (Composer resolves path
settings against the root `composer.json` directory), so the root is
`dirname(vendor-dir)`. This is more robust than `InstallScript`'s
cwd-walking `findProjectRoot()` and works regardless of where composer runs
from and of non-standard vendor-dir layouts.

**Rejected:** falling back to the installed package's own
`vendor/crazy-goat/scanmephp/composer.json` when the root has no checksums —
this quietly restores the fail-open trust model (attacker-rewritable
checksums), which would negate the fix.

## Fail-closed everywhere

`BinaryDownloader::download()` now refuses any download that cannot be
verified:

- ChecksumManager injected **and** a checksum exists for `(version,
  binaryName)` → download + mandatory SHA-256 comparison (already the
  existing code path, now unreachable otherwise);
- ChecksumManager injected but `hasChecksum()` is false → throw
  `DownloadException::checksumMissing()` **before** any HTTP request or file
  creation;
- no ChecksumManager and no explicit `?string $expectedChecksum` →
  throw as well (the `downloadForCurrentPlatform()` convenience path is
  therefore fail-closed by default too);
- explicit `$expectedChecksum` given → verified against it, regardless of
  manager (override path preserved, e.g. for callers with an out-of-band
  checksum).

Verification still happens **before** `chmod()` (the old Plugin.php did
`chmod` immediately after download with no verification at all).

## Version-key normalization in ChecksumManager

`Plugin`/`InstallScript` pass versions without the `v` prefix (`0.4.6`), but
`composer.json` checksum blocks may be keyed either `0.4.6` or `v0.4.6` (the
pre-existing test fixture used `v0.4.4`). `getChecksum()` now accepts both
forms; otherwise the fail-closed check would reject every download even when
checksums are correctly configured. This was pre-identified in the review
reports (bugs-report M13: "klucz wersji wrażliwy na prefiks v").

## What was rejected

- **Signatures (minisign/cosign/GPG).** The issue lists them as "Consider"
  for a follow-up: they protect against repo-takeover in a way a consumer
  pin cannot fully (a republished release could be signed by the attacker's
  key if the org account is taken). Implementing signature verification is a
  much larger change (key distribution/pinning model, verification code,
  release pipeline). Out of scope for this PR — recorded as a follow-up.
- **Making binary installation opt-in (config flag).** The issue proposes it
  as a *stopgap*. With fail-closed checksums it is unnecessary: no checksum
  configured ⇒ no download, loudly. Adding `extra.scanmephp.checksums` to the
  root `composer.json` *is* the opt-in. A separate config flag would be
  redundant today.
- **Adding checksums for current releases to this repo's `composer.json`.**
  This file is the *package's own* composer.json; per the trust-anchor
  decision above it is NOT the file the plugin reads in a consuming project,
  and publishing checksums here would imply a guarantee the plugin does not
  act on. Publishing release checksums belongs to the release workflow (a
  follow-up), and consumers pin them in their root composer.json.
- **Verifying already-present binaries at install time.** Out of scope
  (pre-existing binaries from older installs stay accepted); recorded in
  findings-coder.md.

## Remaining uncertainty

- `Plugin::installBinaries()` now uses the Composer instance captured in
  `activate()` (`$this->composer`) instead of the event's — Composer
  activates plugins with the very instance that later dispatches package
  events, so they are the same object; this keeps the property alive instead
  of leaving it written-but-never-read (which PHPStan flags).
- The old `Plugin::downloadBinary()` derived the version from the **root**
  package (`$this->composer->getPackage()`); the new path uses the
  **scanmephp** package's own version (already computed in
  `installBinaries()`), which is the version whose release tag the URL must
  use. The old code produced a wrong release URL whenever the root project's
  version differed from the installed scanmephp version.
- `InstallScript` (the older installer) is unchanged but automatically
  becomes fail-closed (it already wires `ChecksumManager`); its
  build-from-source fallback now also triggers on checksum refusal — see
  findings-coder.md for the observability gap.
