<?php

namespace Omnibridge\Composer\Patch\Operation;

use Omnibridge\Composer\Patch\InvalidDeclaration;
use Omnibridge\Composer\Patch\Target;

/**
 * {"prepend": "<lines>", "before": "<anchor>"} and {"append": "<lines>",
 * "after": "<anchor>"}: the lines inserted before (after) every line that
 * contains the anchor ("count" to say how many there must be), at the
 * anchor's indentation unless they bring their own. In a PHP file the
 * inserted lines follow a marker comment naming the patch ("mark": false
 * leaves it out).
 */
final class Insert extends Operation
{
    use Lines;

    public function __construct(
        public readonly string $text,
        public readonly string $anchor,
        public readonly bool $before,
        public readonly ?int $expected = null,
        public readonly bool $mark = true,
    ) {
    }

    protected static function declared(string $verb, array $declaration): self
    {
        $before = 'prepend' === $verb;
        $anchorKey = $before ? 'before' : 'after';
        if (!array_key_exists($anchorKey, $declaration)) {
            throw new InvalidDeclaration(sprintf('"%s" needs "%s" (the anchor line)', $verb, $anchorKey));
        }

        return new self(
            self::string($declaration, $verb),
            self::string($declaration, $anchorKey),
            $before,
            self::count($declaration),
            (bool) ($declaration['mark'] ?? true),
        );
    }

    public function apply(string $code, Target $target): string
    {
        $lines = explode("\n", $code);
        $found = self::linesWith($lines, $this->anchor);
        self::expectCount($this->expected, count($found), 'the line with '.self::quote($this->anchor), $target);

        $mark = $this->mark && $target->isPhp($code);
        foreach (array_reverse($found) as $index) {
            $indent = self::indentation($lines[$index]);
            $insert = self::indented($this->text, $indent);
            if ($mark) {
                array_unshift($insert, $indent.self::marker($target));
            }
            array_splice($lines, $this->before ? $index : $index + 1, 0, $insert);
        }

        return implode("\n", $lines);
    }

    public function describe(): string
    {
        return sprintf('%s %s %s the line with %s', $this->before ? 'prepend' : 'append', self::quote($this->text), $this->before ? 'before' : 'after', self::quote($this->anchor));
    }
}
