<?php

namespace Omnibridge\Composer\Stub;

/**
 * A stub provider, declared by a package in extra.omnibridge.stubs (its class
 * name, autoloaded from that package). The engine runs it on
 * post-autoload-dump and writes what it returns under
 * vendor/composer/omnibridge/stubs/<name>/ - a directory no autoloader reads:
 * stubs are for an IDE and static analysis, never definitions at run time.
 *
 * generate() must be deterministic: the same installed tree gives the same
 * files, byte for byte (no date, no absolute path), so that
 * `composer omnibridge:stubs --check` can compare.
 */
interface StubInterface
{
    /** The subdirectory of the stubs, unique among the providers (Entity, Repository...). */
    public function getName(): string;

    /** @return iterable<string, string> path relative to the provider's directory => file content */
    public function generate(StubContext $context): iterable;
}
