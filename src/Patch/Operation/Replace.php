<?php

namespace Omnibridge\Composer\Patch\Operation;

use Omnibridge\Composer\Patch\Target;

/**
 * {"replace": "<text>", "with": "<text>", "count": n}: every occurrence of the
 * exact text (whitespace included); "count", when given, is how many there
 * must be. Unmarked: the text may sit inside an expression.
 */
final class Replace extends Operation
{
    public function __construct(
        public readonly string $search,
        public readonly string $replacement,
        public readonly ?int $expected = null,
    ) {
    }

    protected static function declared(string $verb, array $declaration): self
    {
        $with = $declaration['with'] ?? null;
        if (is_array($with)) {
            $with = implode("\n", $with);
        }
        if (!is_string($with)) {
            throw new \Omnibridge\Composer\Patch\InvalidDeclaration('"replace" needs "with" (a string, possibly empty)');
        }

        return new self(self::string($declaration, 'replace'), $with, self::count($declaration));
    }

    public function apply(string $code, Target $target): string
    {
        self::expectCount($this->expected, substr_count($code, $this->search), self::quote($this->search), $target);

        return str_replace($this->search, $this->replacement, $code);
    }

    public function describe(): string
    {
        return sprintf('replace %s with %s', self::quote($this->search), self::quote($this->replacement));
    }
}
