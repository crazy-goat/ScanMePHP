# Code Decision #1 — Re-verify existing on-disk binaries against pinned checksums (#185)

## Approach taken

Added one method to `ChecksumManager`:

```php
public function existingBinaryIsValid(string $version, string $binaryName, string $path): bool
```

Semantics (exactly per the issue):

- **checksum configured + on-disk hash matches** → `true` → Plugin keeps the
  existing early return (fast path);
- **checksum configured + hash mismatch (or file unhashable, e.g. missing,
  unreadable, a directory)** → `false` (fail-closed);
- **no checksum configured** → `true` (no regression for consumers without
  pinned checksums, issue requirement verbatim).

Both plugin call sites then become:

```php
if (file_exists($targetFile)) {
    if ($checksumManager->existingBinaryIsValid($version, $binaryName, $targetFile)) {
        // ...existing "already exists" fast path...
        return true; // (or return; for FFI)
    }
    unlink($targetFile);
    $this->io->write('⚠️  Existing … failed SHA-256 verification. Re-downloading the verified binary.');
}
// falls through to the existing createDownloader()->download() path
```

The mismatch branch does **not** re-implement download logic: it just unlinks
and falls through into the code that was already there — `BinaryDownloader::download()`,
which is fail-closed (refuses when no checksum, verifies SHA-256 after download,
unlinks and throws `DownloadException::checksumMismatch()` on mismatch). So the
tampered file is replaced through the exact same verified path that protects
new installs; there is no second, weaker code path. If that re-download fails
(no network, checksum missing for the FFI name, etc.) the existing catch blocks
already print the graceful fallback message and the pure-PHP encoder is used.

`hash_file()` is suppressed with `@` inside the helper (precedent:
`@file_get_contents()` in `src/PlatformDetector.php:43`) so a vanished file
between `file_exists()` and the hash (TOCTOU, tracked by #65) degrades to a
quiet `false`, not a PHP warning — still fail-closed.

## Where the logic lives — why ChecksumManager, not a Plugin static

The issue offered two sanctioned options (static helper on `Plugin` or on
`ChecksumManager`). I chose an **instance method on `ChecksumManager`**:

- The decision "is this on-disk file acceptable given the pinned checksums"
  is checksum-domain logic. It reads cleanly next to the existing
  `getChecksum()`/`hasChecksum()` and reuses their version-key normalization
  (`v0.4.4` vs `0.4.4`) for free.
- `Plugin` stays pure orchestration + IO; no new public surface on the plugin
  class (whose constructor requires Composer mocks, making any single-method
  test drag in the whole `PackageEvent` harness).
- Tests for the helper need only a temp dir + composer.json fixture — the
  existing `ChecksumManagerTest` temp-dir pattern, zero new infrastructure.

## What I rejected

1. **Static helper on `Plugin`** — would work, but every test then needs the
   full Composer mock stack even though the method touches no Composer state.
   Also puts binary-content verification (a checksum concern) on the
   orchestration class.
2. **New class (e.g. `ExistingBinaryVerifier`)** — over-engineering: one
   `getChecksum()` call plus one `hash_file()` does not justify a new
   abstraction; DEC-001-era workflow and the issue both warn against it.
3. **Extracting the shared exists-block into a private Plugin helper
   `keepOrReinstallExistingBinary()`** — the two call sites are only
   ~10 lines each and differ in message text, return type (`bool` vs `void`)
   and follow-up instructions; a shared helper would need 3 branching
   parameters and would be *less* readable than the inline blocks.
4. **Refusing with a clear message instead of re-downloading (issue's
   alternative)** — the issue itself prefers re-download "since infrastructure
   exists"; refusing would regress every consumer with a stale-but-valid
   binary from before #48 into a forced FFI/pure-PHP fallback on every
   install until they delete `vendor/` by hand. Re-download self-heals.

## What I was unsure about

- **Tests hitting the network.** The mismatch path ends in a real download
  from GitHub. I verified it end-to-end with a throwaway test (tampered file
  → detected → unlinked → real download → checksum rejection → graceful FFI
  fallback) and deleted it, because it must never run in CI (network + it
  downloads a real artifact). The committed tests cover the decision logic
  (`ChecksumManagerTest`, 4 cases) and the two network-free end-to-end Plugin
  flows (matching-checksum fast path; no-checksum legacy fast path) — both
  fail loudly without touching the network if the plugin's naming logic ever
  changes (see test helper docblock).
- **`file_exists` vs `is_file`.** The exists-check keeps `file_exists()`
  (pre-existing). A *directory* at the target path now trips the verification
  → fails closed → re-download attempt → loud `downloadFailed` message, which
  is acceptable; switching to `is_file()` would silently skip a directory
  and then fail at `fopen('wb')` anyway. Noted in findings-coder.md.

## Verification

- `vendor/bin/phpunit tests/ChecksumManagerTest.php tests/Composer/PluginTest.php` — 11 tests, 21 assertions, OK.
- Full suite `composer test`, lint gates `composer lint` — see findings-coder.md / report.
