# The child plugin, an example

```sh
cd examples/child-plugin
docker compose run --rm example
```

[run.sh](run.sh), in a PHP 8.4 container with Composer 2 (the engine mounted at
`/engine`, read-only):

1. `composer install` of [demo/](demo/), which requires `acme/greeter` 1.2.0
   ([packages/greeter](packages/greeter/): a final `Greeter`, "Hello"); `bin/demo.php`
   prints "Hello, world." and that `Greeter` cannot be extended.
2. `composer require acme/child-plugin` ([packages/child](packages/child/)): it
   requires `omnibridge/composer` and declares, in its `composer.json`, one patch
   (`unfinal` and `default` on `acme/greeter`'s `src/Greeter.php`) and one stub
   provider ([GreeterStub](packages/child/src/Stub/GreeterStub.php), on the engine's
   `AbstractStub`). Then `composer omnibridge:patches`, `--diff`, the stubs, and
   `bin/demo.php` again: "Bonjour, world.", and `App\LoudGreeter` extends `Greeter`.
   `omnibridge:patches --check` and `omnibridge:stubs --check` are green.
3. `composer install` again: vendor/ and the record identical, byte for byte.
4. `composer remove acme/child-plugin`: the engine leaves with it and writes
   `Greeter.php` back as published; `bin/demo.php` says "Hello" again and
   `vendor/acme/greeter` equals the package's source.

The child is a plain package: the project allows one plugin, the engine
(`"allow-plugins": {"omnibridge/composer": true}`). acme/greeter comes from a path
repository with `"symlink": false` - a linked package is never patched, since that
would change its source.

The test suite runs this same script (`tests/Scenario/ChildPluginTest.php`).
