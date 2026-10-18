<?php

namespace Omnibridge\Composer\Patch;

/**
 * A patch did not find what it changes in its target: the target's code moved
 * on (or already does what the patch did). Strict: the command fails with the
 * patch's id and why, unless the patch says it is optional.
 */
final class TargetNotFound extends \RuntimeException
{
}
