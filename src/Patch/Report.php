<?php

namespace Omnibridge\Composer\Patch;

/** What a reconciliation found and did. */
final class Report
{
    /** @var Outcome[] */
    public array $outcomes = [];

    /** @var string[] files written in their patched state */
    public array $patched = [];

    /** @var string[] files written back as published */
    public array $restored = [];

    /** @var string[] files Composer replaced since the last run (reinstall, update): patched again */
    public array $replaced = [];

    /** @var string[] differences between vendor/ and the declarations or the record (a check fails on them) */
    public array $problems = [];

    /** @return Outcome[] */
    public function failures(): array
    {
        return array_values(array_filter($this->outcomes, static fn (Outcome $o) => $o->failed()));
    }

    /** @return Outcome[] */
    public function applied(): array
    {
        return array_values(array_filter($this->outcomes, static fn (Outcome $o) => Outcome::APPLIED === $o->status));
    }

    public function isClean(): bool
    {
        return [] === $this->failures() && [] === $this->problems;
    }

    public function failureMessage(): string
    {
        $lines = ['omnibridge/composer: a declared patch could not be applied (it is strict: say "optional": true or narrow its "versions" if that is expected):'];
        foreach ($this->failures() as $outcome) {
            $lines[] = '  '.$outcome->line();
        }

        return implode("\n", $lines);
    }
}
