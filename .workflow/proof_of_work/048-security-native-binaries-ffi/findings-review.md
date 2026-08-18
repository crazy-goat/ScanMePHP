# Findings — review (round 1)

Issue: #48 — native binaries downloaded by the Composer plugin without
checksum/signature verification → RCE on release-channel compromise.
Branch: `fix/issue-48-security-native-binaries-are-downloaded`
Reviewer: review-critical agent
Date: 2026-08-18

## Findings

src/Composer/Plugin.php:168-171 | Plugin catches checksumMissing in generic \Exception handler and labels it "Extension download failed" / "FFI library download failed" — misleading framing for a security refusal; the exception message IS printed (not silent) but "falling back" language implies transient failure rather than required config action | medium | fixed (round 1) — both install methods now catch DownloadException and branch on checksumMissing(), printing an actionable "⛔ ... refused ... add extra.scanmephp.checksums" message and dropping the misleading "falling back" line; PluginTest assertion strengthened to assert 'refused' + 'extra.scanmephp.checksums'

src/Composer/Plugin.php:153-156 | Existing binaries on disk accepted without re-verification (file_exists early return) — a binary from an older unverified plugin version or tampered post-install stays accepted; transitional risk for existing installs, not an ongoing attack surface | low | not fixed (by design, this PR) — transitional migration concern, not an ongoing hole; new installs are fully protected by the fail-closed download path. Tracked as a follow-up issue candidate in step 14.

src/ChecksumManager.php:42 | ltrim($version, 'v') strips all leading 'v' chars not just one — harmless given the version regex allows at most one 'v' prefix, but semantically imprecise | nit | fixed (round 1) — replaced ltrim($version,'v') with str_starts_with($version,'v') ? substr($version,1) : $version (single-v strip), same three-key lookup order

## Areas checked clean (no findings)

- BinaryDownloader::download() fail-closed guarantee — airtight on all paths
- BinaryDownloader::downloadForCurrentPlatform() — delegates to download(), fail-closed
- Plugin::installExtensionBinary() / installFfiBinary() — all download paths through BinaryDownloader
- Plugin::createDownloader() — always passes ChecksumManager (never null)
- Trust anchor — getProjectRoot() resolves consumer's root composer.json, not package's own
- No fallback to package's own composer.json
- chmod ordering — verification before chmod, unlink on mismatch before chmod
- Version source — from scanmephp package, not root project (old bug fixed)
- Version spoofing — can't bypass checksums (same version for URL and checksum lookup)
- ChecksumManager prefix normalization — strict, deterministic, no collision/bypass
- hash_file() returning false — fail-closed
- Empty/false/0 checksum values — all fail verification
- Error handling — checksumMissing is printed (not silent) in Plugin and InstallScript
- Zero runtime deps — composer.json require unchanged
- No PHPStan baseline/rule relaxed — baseline untouched
- No coverage floor dropped — no CI changes
- No test disabled — all tests pass
- Commit message — follows DEC-002 convention
- CHANGELOG — correct entry under [Unreleased] Fixed
- TLS verification — CURLOPT_SSL_VERIFYPEER enabled
