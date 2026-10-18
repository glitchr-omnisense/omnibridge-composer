<?php

namespace Omnibridge\Composer\Stub;

/** Where a stub provider runs: the project, its vendor directory, the declaring package. */
final class StubContext
{
    public function __construct(
        public readonly string $projectDir,
        public readonly string $vendorDir,
        public readonly string $packageDir,
        public readonly string $packageName,
    ) {
    }
}
