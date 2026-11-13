# Changelog

All notable changes are documented here, per release tag (`v1.0.0`, `v1.1.0`... on the `1.x` branch). The
releases are made by `.github/workflows/release.yml` from this file: the coming changes stay under
`## [Unreleased]` until a version takes them.

## [Unreleased]

## [1.0.0] - 2026-11-13

The first tagged release: what `1.x-dev` installed until now, with nothing removed or renamed.

### Added

- `Omnibridge\Composer\Plugin`: a Composer plugin (`composer-plugin-api` ^2.3) that knows no package. The patches
  and the stub providers come from the installed packages (and the root), in `extra.omnibridge`.
- Patches, applied after `install`, `update`, `require`, `remove` and `reinstall` on the file as published, and
  written back as published when the declaring package or the target leaves, or when `versions` or `php` stop
  matching. Applying twice changes nothing. A patch that no longer finds its target fails the command with its id
  and its `why`, unless it is `"optional": true`. A package installed as a symbolic link is never patched. The
  vocabulary: `replace`, `prepend`, `append`, `erase`, and the AST operations on `nikic/php-parser` ^5.0
  (`unfinal`, `visibility`, `default`, `returns`), plus `"class"` (a `PatchInterface`) for anything else.
- The record, `vendor/composer/omnibridge/patches.json`: each patched file, its package and version, the patches
  applied in order, and the SHA-256 of the file as published and as patched. The published copy is kept in
  `originals/`. It holds no date and no random id, so the same lock gives the same record.
- Stubs: providers (`StubInterface`, `AbstractStub`) run after `dump-autoload` and write into
  `vendor/composer/omnibridge/stubs/`, which no autoloader reads. The output is deterministic, and stale files are
  removed.
- The commands `composer omnibridge:patches` (`--diff`, `--check`, `--apply`) and `composer omnibridge:stubs`
  (`--check`). Their exit codes are for CI.
- `extra.branch-alias` (`dev-1.x`: `1.x-dev`) and `.github/workflows/release.yml`: a pushed tag `vX.Y.Z` runs this
  package's CI, then glitchr-studio/ci's `php-release.yml`.
