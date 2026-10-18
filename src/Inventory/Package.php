<?php

namespace Omnibridge\Composer\Inventory;

/**
 * An installed package as the engine sees it: where it is, which version, what
 * it declares. The root package is one of them (its path is the project).
 */
final class Package
{
    public const ROOT = '__root__';

    /**
     * @param string $version  normalized (8.1.6.0, dev-main, 4.9999999.9999999.9999999-dev)
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $autoload
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $prettyVersion,
        public readonly string $path,
        public readonly array $extra = [],
        public readonly array $autoload = [],
        public readonly bool $root = false,
    ) {
    }

    /** extra.omnibridge.<key>, a list. */
    public function declares(string $key): array
    {
        $value = $this->extra['omnibridge'][$key] ?? [];

        return is_array($value) ? $value : [$value];
    }

    /**
     * Installed as a symbolic link (a path repository): a patch would change
     * its source tree, not a copy.
     */
    public function isLinked(): bool
    {
        return !$this->root && is_link(rtrim($this->path, '/'));
    }
}
