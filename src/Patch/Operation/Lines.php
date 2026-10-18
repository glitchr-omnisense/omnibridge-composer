<?php

namespace Omnibridge\Composer\Patch\Operation;

use Omnibridge\Composer\Patch\Target;

/** The line-based edits (prepend, append, erase): finding lines, marking them. */
trait Lines
{
    /**
     * The indexes of the lines that contain $anchor.
     *
     * @param string[] $lines
     * @return int[]
     */
    private static function linesWith(array $lines, string $anchor): array
    {
        return array_keys(array_filter($lines, static fn (string $line) => str_contains($line, $anchor)));
    }

    private static function indentation(string $line): string
    {
        return substr($line, 0, strlen($line) - strlen(ltrim($line, " \t")));
    }

    /** The marker line of an edit in a PHP file: the patch that wrote what follows. */
    private static function marker(Target $target): string
    {
        return '// [omnibridge '.$target->patch.']';
    }

    /**
     * $text as lines at $indent: a text that brings its own indentation keeps
     * it; one that does not takes the anchor's.
     *
     * @return string[]
     */
    private static function indented(string $text, string $indent): array
    {
        $lines = explode("\n", $text);
        if ('' !== $lines[0] && self::indentation($lines[0]) !== '') {
            return $lines;
        }

        return array_map(static fn (string $line) => '' === $line ? $line : $indent.$line, $lines);
    }
}
