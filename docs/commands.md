---
title: Commands
order: 35
---

# Commands

The plugin adds two commands to Composer (`Composer\Plugin\Capability\CommandProvider`).

## omnibridge:patches

```sh
composer omnibridge:patches [--diff] [--check] [--apply]
```

| Mode | What it does | Exit code |
|---|---|---|
| (none) | computes every declared patch without writing, and lists them: `N declared`, then `applied`, `skipped`, `failed` groups, each patch with its target package and file (and `versions`), its `why`, its `upstream`, and what it does (applied) or why it was skipped or failed; the problems as warnings. `No package declares a patch (extra.omnibridge.patches).` when there is none | 1 when a patch fails, else 0 |
| `--diff` | a unified diff of each recorded file, as published (`a/<package>/<file>`) against as installed (`b/...`) | 0 |
| `--check` | computes without writing and compares with vendor/ and the record: fails on a patch that no longer finds its target, a file that differs from the record, a published copy missing or altered, a file not in the declared state, a patch never applied, a recorded file no patch applies to any more. `omnibridge:patches --check: N patch(es) applied as recorded, vendor/ as declared` when clean | 1 on any failure or problem (`omnibridge:patches --check: N problem(s)`), else 0 |
| `--apply` | applies now and writes, as install and update do (after a run with `--no-plugins`), printing what the hooks print | 1 when a patch fails, else 0 |

`--apply` wins over `--diff`, which wins over `--check`, when several are given.

The statuses of a patch:

| Status | When |
|---|---|
| `applied` | its operations found their targets |
| `skipped: package not installed` | the target package is absent (or is the root) |
| `skipped: version` | the target's installed version does not match `versions` |
| `skipped: php` | the project's PHP (`config.platform.php`, else the running PHP) does not match `php` |
| `skipped: linked package` | the target is installed as a symbolic link |
| `skipped: optional, target not found` | an `optional` patch that found nothing |
| `FAILED` | a target not found (or already patched), an invalid declaration, a patch declared twice by one package |

## omnibridge:stubs

```sh
composer omnibridge:stubs [--check]
```

| Mode | What it does | Exit code |
|---|---|---|
| (none) | runs every declared provider into `vendor/composer/omnibridge/stubs/`: `omnibridge:stubs: <name> <count>, ...` and `N written, M removed` when something changed | 1 when a provider is missing, not a `StubInterface`, has an invalid name or path, or two providers share a name; else 0 |
| `--check` | the same, writing nothing: also fails on a stub missing, a stub that is not what its provider generates, a file no provider returns any more | 1 on any problem, else 0 |

`no provider declared` when no package declares one.

## What the hooks print

During `install`, `update`, `require`, `remove`:

- `omnibridge/composer: stubs <name> <count> (vendor/composer/omnibridge/stubs)` after each autoloader
  dump (`post-autoload-dump`), and again after the patches when they changed or restored a file;
- `omnibridge/composer: patches`, then one line per patch (`applied`, `skipped`, `FAILED`), the files
  Composer replaced (`... was replaced by Composer: patched again`), the files written back as
  published, the problems (`post-install-cmd`, `post-update-cmd`);
- a failure ends the command with `omnibridge/composer: a declared patch could not be applied (it is
  strict: say "optional": true or narrow its "versions" if that is expected):` and the failed patches'
  lines;
- on the engine's own removal: `omnibridge/composer: <file> written back as published` for each
  recorded file, the stubs and the record removed.

In CI, after `composer install`:

```sh
composer omnibridge:patches --check
composer omnibridge:stubs --check
```
