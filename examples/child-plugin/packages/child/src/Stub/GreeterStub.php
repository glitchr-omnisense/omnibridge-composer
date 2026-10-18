<?php

namespace Acme\Child\Stub;

use Omnibridge\Composer\Stub\AbstractStub;
use Omnibridge\Composer\Stub\StubContext;

/**
 * Stubs of App\Greeter\X for every plain class Acme\Greeter\X, unless the
 * application defines App\Greeter\X in src/Greeter/: what an IDE needs when a
 * package aliases its classes under the application's namespace at run time
 * (omnibase does it for Base\ and App\). Run by omnibridge/composer only.
 */
final class GreeterStub extends AbstractStub
{
    public function getName(): string
    {
        return 'Greeter';
    }

    protected function getSourceNamespace(): string
    {
        return 'Acme\Greeter';
    }

    protected function getSourceDirectory(StubContext $context): string
    {
        return $context->vendorDir.'/acme/greeter/src';
    }

    protected function getTargetNamespace(): string
    {
        return 'App\Greeter';
    }

    protected function getApplicationDirectory(StubContext $context): ?string
    {
        return $context->projectDir.'/src/Greeter';
    }
}
