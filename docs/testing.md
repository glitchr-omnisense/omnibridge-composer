---
title: Testing
order: 60
---

# Testing

## In a project's CI

After `composer install`:

```sh
composer omnibridge:patches --check
composer omnibridge:stubs --check
```

The first fails when a dependency's update made a patch useless or broken (the patch says its `why`
and its `upstream`), or when vendor/ was changed by hand; the second when a provider's output changed
without a dump. A child tests its own patches by installing a project that requires it - the engine's
`examples/child-plugin` is the pattern.

## The package's suite

```sh
docker compose run --rm test
```

The service `test`: the image `omnibridge-composer:php8.4` (`docker/Dockerfile`: PHP 8.4-cli with git,
unzip and Composer 2), the package at `/engine`, a named volume `composer-cache`; it runs
`composer install` then `vendor/bin/phpunit`. With the checkout's `vendor/` installed:

```sh
docker run --rm --network none -v "$PWD":/engine:ro omnibridge-composer:php8.4 vendor/bin/phpunit --do-not-cache-result
```

| Test class | What it checks |
|---|---|
| `OperationsTest` | `replace` every occurrence and with `count`; `prepend` marked and indented, `append` unmarked; `erase` keeps the line as a comment; `unfinal` removes the keyword and the tag, refuses a class that is not final; `visibility` of a promoted parameter, a property, a method, a constant, of every private member, where it is implicit, refused when already there; `default` of a method and a function, refused when already there; `returns` of a method and a function, refused when already there; everything else of the file keeps its bytes; each target not found says what; a declaration says one verb; code that does not parse |
| `ReconcilerTest` (a fake vendor directory) | apply records checksums and keeps the published file; applying again changes nothing; restored when the declaring package leaves, when the version stops matching; skipped by PHP; a strict failure says the id and why and touches nothing; an optional patch that finds nothing is skipped; a class patch; two patches on one file in a fixed order; a file Composer replaced is patched again; `--check` fails when a file differs from the record, when a patch was never applied; a linked package is never patched; an absent package is skipped and an invalid declaration fails; the root declares too; restore all |
| `StubGeneratorTest` | plain classes without an application class get a stub; deterministic and checked; a stub whose class is gone is removed; no provider removes them all |
| `DiffTest` | a unified diff with two hunks; the same content, no diff |
| `Scenario\ChildPluginTest` | the example from start to end (`examples/child-plugin/run.sh`) |

`ChildPluginTest` runs Composer for real and needs Packagist (for `nikic/php-parser`): without network
it fails with Composer's exit code 100.
