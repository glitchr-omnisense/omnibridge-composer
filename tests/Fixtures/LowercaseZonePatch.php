<?php

namespace Omnibridge\Composer\Tests\Fixtures;

use Omnibridge\Composer\Patch\PatchInterface;
use Omnibridge\Composer\Patch\Target;
use Omnibridge\Composer\Patch\TargetNotFound;

/** A "class" patch: what the vocabulary cannot say (here, a computed edit). */
final class LowercaseZonePatch implements PatchInterface
{
    public function apply(string $code, Target $target): string
    {
        $changed = preg_replace_callback("/'([A-Z]{3})'/", static fn (array $m) => "'".strtolower($m[1])."'", $code);
        if (null === $changed || $changed === $code) {
            throw new TargetNotFound('no upper-case zone in '.$target->file);
        }

        return $changed;
    }
}
