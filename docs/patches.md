# Patches

A patch is declared by the package that needs it (or by the root), never by the
engine: `extra.omnibridge.patches`, a list.

```json
{
    "id": "process-timeout-from-env",
    "package": "symfony/process",
    "versions": "^6.0 || ^7.0 || ^8.0",
    "php": ">=8.2",
    "file": "Process.php",
    "why": "One sentence: what the patch changes and what for.",
    "upstream": "https://github.com/symfony/symfony/issues/...",
    "optional": false,
    "do": [
        { "default": "null", "of": "Symfony\\Component\\Process\\Process::__construct($timeout)" }
    ]
}
```

| Key | | |
|---|---|---|
| `id` | required | letters, digits, `.`, `-`, `_`; unique in the declaring package. The patch is named `<declaring package>:<id>` (`__root__:<id>` for the root) |
| `package` | required | the target package |
| `file` | required | the file, relative to the target package (one file per patch) |
| `why` | required | printed with every failure |
| `do` | `do` or `class` | one operation or a list, applied in order |
| `class` | `do` or `class` | a `Omnibridge\Composer\Patch\PatchInterface`, autoloaded from the declaring package |
| `versions` | optional | a Composer constraint on the target's installed version |
| `php` | optional | a constraint on the PHP the project installs for (`config.platform.php`, else the running PHP) |
| `upstream` | optional | where the change is (or should be) proposed |
| `optional` | optional | `true`: a target not found is a skip, not a failure |

## When

After `composer install` and `composer update` - so after `require`, `remove` and
`reinstall` too, every package being on disk then. The engine computes each
touched file from its published content (the copy the record kept, or the file
itself when Composer has just written it) with every applicable patch, in a fixed
order: declaring packages by name, the root last, each package's patches in its
order. A file that no patch applies to any more is written back as published:

- the declaring package was removed;
- the target's version or PHP stopped matching `versions` / `php`;
- the engine itself was removed (it writes everything back on uninstall).

A target installed as a symbolic link (a path repository with `"symlink": true`)
is never patched: that would change its source. It is reported as skipped.

## Strict

Every operation fails when it does not find what it changes - and also when the
change is already there (a parameter that already defaults to the value, a class
that is no longer final): a patch that has become useless says so, which is how
its declaring package learns to drop it or narrow `versions`. A failure stops the
command (`install`, `update`...) with the patch's name, what was not found and the
patch's `why`; the file is left as published. `"optional": true` turns it into a
skip.

## The vocabulary

Two kinds of edit. The AST ones locate their target with nikic/php-parser by name
- a class, a member, a parameter - whatever the spacing, the attributes or the
line it is on, and splice at the node's offsets (the rest of the file keeps its
bytes). The text ones match exact text; in a PHP file the lines they add are
marked with a comment naming the patch, `// [omnibridge <package>:<id>]`.

### `replace` (text)

```json
{ "replace": "new HtmlErrorRenderer(", "with": "new \\App\\HtmlErrorRenderer(", "count": 1 }
```

Every occurrence of the exact text (whitespace included). `count`, when given, is
how many there must be. Unmarked: the text may be inside an expression. `with` may
be empty.

### `prepend`, `append` (text, marked)

```json
{ "prepend": "$timeout ??= $_ENV['SYMFONY_PROCESS_TIMEOUT'] ?? 60;", "before": "$this->setTimeout($timeout);" }
{ "append": "...", "after": "..." }
```

The lines inserted before (after) every line that contains the anchor (`count`
to say how many), at the anchor's indentation unless the text brings its own,
after a marker line in a PHP file. `"mark": false` leaves the marker out. A list
of strings is joined with new lines.

### `erase` (text, marked)

```json
{ "erase": "function mb_ucfirst(" }
```

Every line that contains the anchor. In a PHP file the line stays as a comment
after the marker (`// [omnibridge acme/child:id] function mb_ucfirst(...`), so what
was there remains readable; `"mark": false` or another file type removes it.

### `unfinal` (AST)

```json
{ "unfinal": "Vendor\\Package\\Class" }
{ "unfinal": ["Vendor\\Package\\A", "Vendor\\Package\\B"] }
```

The class's `final` keyword and the `@final` line of its docblock (the one
Symfony's DebugClassLoader reads) removed. A class that is neither is not found.

### `visibility` (AST)

```json
{ "visibility": "protected", "of": "Vendor\\Package\\Class::$property" }
{ "visibility": "protected", "of": ["Class::method()", "Class::CONSTANT", "Class::*"] }
```

The member's visibility keyword changed; a promoted constructor parameter counts
as a property; a method or constant without a keyword gets one. `Class::*`
changes every private member of the class. A member that already has the
visibility is not found.

### `default` (AST)

```json
{ "default": "null", "of": "Vendor\\Package\\Class::method($parameter)" }
{ "default": "'fr'", "of": "Vendor\\Package\\function($parameter)" }
```

The parameter's default value replaced by the expression, written as PHP. A
parameter without a default, or that already defaults to it, is not found.

### `returns` (AST)

```json
{ "returns": "void", "of": "Vendor\\Package\\Class::method()" }
{ "returns": "?string", "of": ["Class::a()", "Vendor\\Package\\function()"] }
```

The declared return type replaced, written as PHP - for a subclass whose
override PHP refuses (a parent's `never` where the child returns `void`). A
method without a declared return type, or that already returns it, is not found.

### `class`

```json
{ "id": "...", "package": "...", "file": "...", "why": "...", "class": "Vendor\\Child\\Patch\\MyPatch" }
```

```php
final class MyPatch implements \Omnibridge\Composer\Patch\PatchInterface
{
    public function apply(string $code, \Omnibridge\Composer\Patch\Target $target): string
    {
        // $code is the file as published; return it changed, or throw
        // Omnibridge\Composer\Patch\TargetNotFound. Unchanged counts as not found.
    }
}
```

For what the vocabulary cannot say. It is recorded, restored and checked like the
others; it must be a function of the published content (no date, nothing random).

## The record

`vendor/composer/omnibridge/patches.json`:

```json
{
    "files": {
        "symfony/process/Process.php": {
            "package": "symfony/process",
            "version": "v8.1.6",
            "file": "Process.php",
            "path": "vendor/symfony/process/Process.php",
            "before": "sha256:...",
            "after": "sha256:...",
            "patches": ["omniscient/omnibase:process-timeout-from-env"]
        }
    }
}
```

and `vendor/composer/omnibridge/originals/<package>/<file>`, the published file. No
date and no random id: two installs of one lock write the same record. A file that
no longer has the recorded `after` was replaced by Composer (a reinstall, an update)
and is patched again on its new content.

## Commands

- `composer omnibridge:patches` - every declared patch: applied (what it does),
  skipped (package not installed, version, PHP, linked package, optional and not
  found) or failed, with the target, `why`, `upstream`.
- `composer omnibridge:patches --diff` - a unified diff of each patched file, as
  published against as installed.
- `composer omnibridge:patches --check` - for CI, after `composer install`: fails
  when a patch no longer finds its target, when a file differs from the record,
  when a published copy is missing or altered, or when vendor/ is not in the state
  the declarations give (a patch never applied, one no longer declared).
- `composer omnibridge:patches --apply` - applies now, as install and update do
  (after a run with `--no-plugins`, say).
