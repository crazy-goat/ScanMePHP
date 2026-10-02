# Release workflow

One milestone = one release. Versions follow
[Semantic Versioning](https://semver.org/) and the changelog follows
[Keep a Changelog](https://keepachangelog.com/). Tags are `vX.Y.Z`.

Everything is written in **English**, including release notes.

## 1. Release gate

A release is ready when the milestone has **no open issues**:

```bash
gh api repos/{owner}/{repo}/milestones --jq '.[] | select(.title=="vX.Y.Z") | {title, open_issues, closed_issues}'
```

- Open issues that will not make it: move them to the next milestone
  (`gh issue edit <N> --milestone vX.Y.(Z+1)`).
- The default branch must have a green `ci-ok`.

## 2. Choose the version

| Change | Bump |
|---|---|
| Bug fixes only | patch (`1.2.3` → `1.2.4`) |
| New, backward compatible features | minor (`1.2.3` → `1.3.0`) |
| Breaking changes | major (`1.2.3` → `2.0.0`) |

Before `1.0.0`, breaking changes bump the minor version. The milestone title
already holds the planned version. Change the milestone title if the plan changed.

## 3. Prepare the CHANGELOG (pull request)

```bash
git switch -c chore/release-vX.Y.Z
```

In `CHANGELOG.md`:

- Rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD`.
- Add a fresh empty `## [Unreleased]` above it.
- Group entries under Added, Changed, Deprecated, Removed, Fixed, Security.
- Update the compare links at the bottom, if the file has them.
- Bump `PHP_SCANME_QR_VERSION` in `php-ext/php_scanme_qr.h` to `X.Y.Z`.
  `bin/build-ext-mirror.sh --push` refuses a tag that does not match it.
  `composer.json` carries no version: Packagist reads the git tag.

Open a PR titled `chore: release vX.Y.Z`, wait for `ci-ok`, squash merge.

## 4. Tag

Tag the merge commit on the default branch with an **annotated** tag:

```bash
git switch <default-branch> && git pull --ff-only
git tag -a vX.Y.Z -m "Release vX.Y.Z"
git push origin vX.Y.Z
```

## 5. GitHub Release

Pushing the tag starts `.github/workflows/release-build.yml`. GitHub runs the workflow
file from the **tagged commit**, so the release PR with the `## [X.Y.Z]` section
must be **merged before** you tag.

The workflow builds the binaries and attaches them to the release:

| Asset | Platforms |
|---|---|
| `libscanme_qr-linux-glibc-x86_64.so`, `libscanme_qr-linux-musl-x86_64.so` | FFI library, Linux |
| `libscanme_qr-macos-x86_64.dylib`, `libscanme_qr-macos-arm64.dylib` | FFI library, macOS |
| `php-ext-linux-{glibc,musl}-x86_64-php{8.2,8.3,8.4}.so` | PHP extension, Linux (6 files) |
| `php-ext-macos-{x86_64,arm64}-php{8.2,8.3,8.4}.so` | PHP extension, macOS (6 files) |

The `release` job runs only when every build job succeeded. It extracts the notes of the
matching `CHANGELOG.md` section (cut at 120000 characters, below the GitHub limit of
125000) and runs `gh release create --verify-tag` with the assets. It fails when the
section is missing or empty. Tags with a `-` (for example `v0.6.0-rc.1`) become
pre-releases. If the release already exists, the workflow only uploads the assets to it.

Running the workflow by hand (`workflow_dispatch`) builds every binary and publishes
nothing. Do this before tagging when you touched the toolchain, `clib/` or `php-ext/`.

```bash
gh run watch
gh release view vX.Y.Z
```

A failed build job means a release without binaries. Packagist already holds the tag and
it cannot be moved, so fix forward with the next patch release.

## 6. Close the milestone

```bash
gh api -X PATCH repos/{owner}/{repo}/milestones/<number> -f state=closed
```

Make sure the next milestone `vX.Y.(Z+1)` (or the next minor) exists.

## 7. After the release

- Publish the `crazy-goat/qrcode-ext` PIE package, which is generated from this
  repository, from a clean checkout of the tag:

  ```bash
  git switch --detach vX.Y.Z
  bash bin/build-ext-mirror.sh --push vX.Y.Z
  ```

- Check that install instructions work with the new version (`composer require
  crazy-goat/scanmephp`, `pie install crazy-goat/qrcode-ext`).
- If something is wrong, do not move the tag. Fix forward with a patch release.

## Checklist

- [ ] Milestone has no open issues, CI is green
- [ ] CHANGELOG section `[X.Y.Z] - date` written, `[Unreleased]` is empty
- [ ] Release PR merged
- [ ] `PHP_SCANME_QR_VERSION` bumped
- [ ] Annotated tag `vX.Y.Z` pushed
- [ ] GitHub Release exists with the CHANGELOG notes and all binaries
- [ ] `qrcode-ext` mirror published
- [ ] Milestone closed, next milestone exists
