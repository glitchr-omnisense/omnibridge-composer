# omnibridge/composer

A Composer plugin that knows no package. What it does comes from the packages installed (and the
root), in `extra.omnibridge`: **patches** applied to other packages' files after install and update,
recorded with checksums and written back as published when they no longer apply, and **stub
providers** that write IDE stubs after the autoloader is dumped.

```sh
git clone https://github.com/glitchr-omnisense/omnibridge-composer.git
cd omnibridge-composer/examples/child-plugin
docker compose run --rm example
```

That runs [the example](https://github.com/glitchr-omnisense/omnibridge-composer/tree/1.x/examples/child-plugin):
a project installs `acme/greeter` (a final `Greeter` that says "Hello"), then requires
`acme/child-plugin`, the smallest child of this engine - one patch, one stub provider. Composer
applies the patch (`Greeter` no longer final, "Bonjour" by default), writes the stubs, the program
greets in French through a subclass, `--check` is green, a second install changes nothing, and
removing the child gives `acme/greeter` back as published ([Installation](installation.md) has the
whole run).

## What it is

```json
"extra": {
    "omnibridge": {
        "patches": [
            {
                "id": "greeter-in-french",
                "package": "acme/greeter",
                "versions": "^1.0",
                "file": "src/Greeter.php",
                "why": "The demo greets in French and extends Greeter, which acme/greeter ships final.",
                "do": [
                    { "unfinal": "Acme\\Greeter\\Greeter" },
                    { "default": "'Bonjour'", "of": "Acme\\Greeter\\Greeter::__construct($greeting)" }
                ]
            }
        ],
        "stubs": ["Acme\\Child\\Stub\\GreeterStub"]
    }
}
```

- **Patches** - applied after `install` and `update` (and `require`, `remove`, `reinstall`), on the
  file as published; written back as published when the declaring package leaves, the target leaves,
  or `versions` / `php` stop matching; applying twice changes nothing. Strict: a patch that no longer
  finds its target fails the command with its id and its `why`, unless it says `"optional": true`. A
  package installed as a symbolic link (a path repository) is never patched. The vocabulary -
  `replace`, `prepend`, `append`, `erase`, `unfinal`, `visibility`, `default`, `returns` - and
  `"class"` for what it cannot say: [Patches](patches.md).
- **The record** - `vendor/composer/omnibridge/patches.json`: each patched file, its package and
  version, the patches applied in order, the SHA-256 of the file as published and as patched; the
  published copy beside it in `originals/`. No date, no random id: the same lock gives the same record.
- **Stubs** - providers (`StubInterface`; `AbstractStub` mirrors a namespace's classes under another)
  run after `dump-autoload` into `vendor/composer/omnibridge/stubs/`, which no autoloader reads: for an
  IDE and static analysis, never a definition at run time. Deterministic, stale files removed:
  [Stubs](stubs.md).

## Commands

```sh
composer omnibridge:patches           # declared: applied, skipped (why), failed
composer omnibridge:patches --diff    # what the applied ones changed (unified diff)
composer omnibridge:patches --check   # CI: fails if a patch lost its target or vendor/ differs from the record
composer omnibridge:patches --apply   # apply now (what install and update do)
composer omnibridge:stubs [--check]   # regenerate the stubs, or check them (CI)
```

Each command, its output and its exit codes: [Commands](commands.md).

## A child

A child is a plain package (`"type": "library"`) that requires `omnibridge/composer` and declares
`extra.omnibridge`. Only the engine is a Composer plugin, so a project allows one plugin, whatever the
number of children:

```json
"config": { "allow-plugins": { "omnibridge/composer": true } }
```

omnisense/omnibase (4.0) is the first real one: its stub providers (`Base\Bridge\Composer\Stub\*`, the
`App\` stubs of `Base\Entity`, `Enum`, `Form`, `Notifier` and `Repository`) and its patch of
symfony/process are declared in its `composer.json`. Writing one: [A child](children.md).

## How this manual is organised

- [Installation](installation.md): requirements, allowing the plugin, the example's whole run.
- [Patches](patches.md): the declaration, when patches run, strictness, the vocabulary, the record.
- [Stubs](stubs.md): providers, `AbstractStub`.
- [Commands](commands.md): `omnibridge:patches` and `omnibridge:stubs`, every option, the messages.
- [A child](children.md): a package that declares patches and stubs, its classes, the hooks' order.
- [Errors](errors.md), [Testing](testing.md), [Verified, and not](verified.md).

## Licence

MIT.
