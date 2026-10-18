<?php

namespace Omnibridge\Composer\Patch;

/** The file a patch changes, and the package it belongs to. */
final class Target
{
    public function __construct(
        public readonly string $package,
        public readonly string $version,
        public readonly string $file,
        public readonly string $path,
        public readonly string $patch,
    ) {
    }

    /** Whether the file is PHP (its edits can be marked with a // comment). */
    public function isPhp(string $code): bool
    {
        return str_ends_with($this->file, '.php') || str_starts_with($code, '<?php') || str_starts_with($code, "#!/usr/bin/env php");
    }
}
