---
title: Verified, and not
order: 90
---

# Verified, and not

## What the tests prove

Run on 2026-10-27 in `omnibridge-composer:php8.4`, the checkout's own `vendor/`, no network: 48
tests, 121 assertions, 47 green; `Scenario\ChildPluginTest` failed with Composer's exit code 100, the
network being off (it installs `nikic/php-parser` from Packagist). The same scenario was then run by
hand with the engine's own `vendor/nikic/php-parser` as a path repository and Packagist turned off,
and went through every step, its output pasted in [Installation](installation.md#the-example-run).

## Verified elsewhere

- omnisense/omnibase 4.0, the first real child: its stub providers (`App\` stubs of `Base\Entity`,
  `Enum`, `Form`, `Notifier`, `Repository`) and its patch of symfony/process
  (`process-timeout-from-env`), on its harness and its sites; a site's commit after the Omnisense
  rename (nakaya-gourmet 732436b, 2026-10-26) states `omnibridge:patches` and
  `omnibridge:stubs --check` green.

## What is not verified

- Composer versions other than the image's (composer-plugin-api ^2.3 is admitted).
- PHP 8.2 and 8.3: composer.json admits them; the image is PHP 8.4.
- Windows: not tried.
- A `class` patch or a stub provider that needs a dependency of its child: the engine loads the
  child's own autoload entries only ([A child](children.md#its-classes-inside-composer)).
