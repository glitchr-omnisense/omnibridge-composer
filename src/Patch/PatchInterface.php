<?php

namespace Omnibridge\Composer\Patch;

/**
 * A patch the vocabulary cannot say, declared with "class" instead of "do".
 *
 * apply() is a function of the file's published content: it gets the
 * file as released (never an already patched one) and returns it changed. It
 * throws TargetNotFound when it cannot find what it changes; returning the
 * code unchanged counts as not found. The engine records, restores and checks
 * it like any declared patch.
 */
interface PatchInterface
{
    public function apply(string $code, Target $target): string;
}
