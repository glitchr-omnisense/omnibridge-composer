<?php

namespace Omnibridge\Composer\Patch\Operation;

use Omnibridge\Composer\Patch\Target;

/**
 * {"erase": "<anchor>"}: every line that contains the anchor ("count" to say
 * how many). In a PHP file the line is kept as a comment after the patch's
 * marker, so that what was there stays readable ("mark": false removes it
 * outright); elsewhere it is removed.
 */
final class Erase extends Operation
{
    use Lines;

    public function __construct(
        public readonly string $anchor,
        public readonly ?int $expected = null,
        public readonly bool $mark = true,
    ) {
    }

    protected static function declared(string $verb, array $declaration): self
    {
        return new self(self::string($declaration, 'erase'), self::count($declaration), (bool) ($declaration['mark'] ?? true));
    }

    public function apply(string $code, Target $target): string
    {
        $lines = explode("\n", $code);
        $found = self::linesWith($lines, $this->anchor);
        self::expectCount($this->expected, count($found), 'the line with '.self::quote($this->anchor), $target);

        $mark = $this->mark && $target->isPhp($code);
        foreach (array_reverse($found) as $index) {
            if ($mark) {
                $lines[$index] = self::indentation($lines[$index]).self::marker($target).' '.ltrim($lines[$index], " \t");
            } else {
                array_splice($lines, $index, 1);
            }
        }

        return implode("\n", $lines);
    }

    public function describe(): string
    {
        return sprintf('erase the line with %s', self::quote($this->anchor));
    }
}
