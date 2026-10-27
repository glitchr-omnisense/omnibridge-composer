---
title: A child
order: 40
---

# A child

A child is a plain package (`"type": "library"`) that requires `omnibridge/composer` and declares, in
its `composer.json`, `extra.omnibridge.patches` (a list of patches) and `extra.omnibridge.stubs` (a list
of provider class names). The root package may declare both too (its patches are named
`__root__:<id>`).

```json
{
    "name": "acme/child-plugin",
    "type": "library",
    "require": { "omnibridge/composer": "1.x-dev", "acme/greeter": "^1.0" },
    "autoload": { "psr-4": { "Acme\\Child\\": "src/" } },
    "extra": {
        "omnibridge": {
            "patches": [{ "id": "greeter-in-french", "package": "acme/greeter", "versions": "^1.0", "file": "src/Greeter.php", "why": "...", "do": [{ "unfinal": "Acme\\Greeter\\Greeter" }] }],
            "stubs": ["Acme\\Child\\Stub\\GreeterStub"]
        }
    }
}
```

## Its classes inside Composer

A `"class"` patch and a stub provider are the child's own classes, run inside Composer's process. The
engine makes them loadable from the child's `psr-4`, `psr-0` and `classmap` autoload entries, for the
time they run - never its `files` (that would run the child's code inside Composer). So a provider or
a class patch:

- must have a constructor without arguments;
- may use the child's own classes and PHP, not the child's dependencies (they are not loaded by
  Composer's process, unless Composer itself has them);
- must be deterministic: the same tree gives the same bytes, no date, no absolute path, nothing
  random - the record and `--check` rely on it.

| Interface | Method | Contract |
|---|---|---|
| `Omnibridge\Composer\Patch\PatchInterface` | `apply(string $code, Target $target): string` | `$code` is the file as published (or as the earlier patches of the same file left it); return it changed, or throw `TargetNotFound`; unchanged counts as not found. Any other exception becomes `TargetNotFound` |
| `Omnibridge\Composer\Stub\StubInterface` | `getName(): string` | the subdirectory, letters, digits, `.`, `_`, `-`, unique among providers |
| | `generate(StubContext $context): iterable` | relative path => content; a path with `..` or empty is refused |

`Target`: `package`, `version` (pretty), `file` (relative to the package), `path` (absolute), `patch`
(its key); `isPhp($code)` (a `.php` file, or code starting with `<?php` or a PHP shebang).
`StubContext`: `projectDir`, `vendorDir`, `packageDir` (the child's), `packageName`.

## The order of things

1. Composer installs or updates the packages, then dumps the autoloader: `post-autoload-dump` runs the
   stub providers.
2. `post-install-cmd` / `post-update-cmd`: every patch, packages by name and the root last, each
   package's in its order, on each file's published content.
3. When a file was patched or written back, the stub providers run again (a class no longer final
   may now get a stub).
4. A failed patch fails the Composer command, once every file is written: the failed patch is left
   out, its file holds the other patches that apply to it, or its published content when none does.
