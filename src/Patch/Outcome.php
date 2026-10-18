<?php

namespace Omnibridge\Composer\Patch;

/** What became of one declared patch. */
final class Outcome
{
    public const APPLIED = 'applied';
    public const SKIPPED_ABSENT = 'skipped: package not installed';
    public const SKIPPED_VERSION = 'skipped: version';
    public const SKIPPED_PHP = 'skipped: php';
    public const SKIPPED_LINKED = 'skipped: linked package';
    public const SKIPPED_OPTIONAL = 'skipped: optional, target not found';
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $key,
        public readonly string $status,
        public readonly string $reason = '',
        public readonly ?Patch $patch = null,
    ) {
    }

    public function failed(): bool
    {
        return self::FAILED === $this->status;
    }

    /** One line: the patch, its status, why. */
    public function line(): string
    {
        $why = null !== $this->patch && self::FAILED === $this->status ? ' (the patch: '.$this->patch->why.')' : '';

        return sprintf('%-9s %s%s%s', self::APPLIED === $this->status ? 'applied' : (self::FAILED === $this->status ? 'FAILED' : 'skipped'), $this->key, '' !== $this->reason ? ' - '.$this->reason : '', $why);
    }
}
