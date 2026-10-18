<?php

namespace Omnibridge\Composer\Stub;

/** What a stub run generated, wrote and removed. */
final class StubReport
{
    /** @var array<string, int> stubs per provider name */
    public array $counts = [];

    /** @var string[] */
    public array $written = [];

    /** @var string[] */
    public array $removed = [];

    /** @var string[] */
    public array $problems = [];

    public function summary(): string
    {
        return implode(', ', array_map(static fn (string $name, int $count) => "$name $count", array_keys($this->counts), $this->counts));
    }
}
