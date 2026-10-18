<?php

namespace Omnibridge\Composer\Patch;

/**
 * A unified diff of two versions of a file, for `omnibridge:patches --diff`.
 * The lines both share at the start and at the end are set aside first, so
 * the longest-common-subsequence table only spans the patched region.
 */
final class Diff
{
    public static function unified(string $before, string $after, string $label, int $context = 3): string
    {
        if ($before === $after) {
            return '';
        }

        $a = explode("\n", $before);
        $b = explode("\n", $after);

        $prefix = 0;
        $max = min(count($a), count($b));
        while ($prefix < $max && $a[$prefix] === $b[$prefix]) {
            ++$prefix;
        }
        $suffix = 0;
        while ($suffix < $max - $prefix && $a[count($a) - 1 - $suffix] === $b[count($b) - 1 - $suffix]) {
            ++$suffix;
        }

        $midA = array_slice($a, $prefix, count($a) - $prefix - $suffix);
        $midB = array_slice($b, $prefix, count($b) - $prefix - $suffix);

        // The edit script: [' ', line], ['-', line], ['+', line], over all lines.
        $script = [];
        foreach (array_slice($a, 0, $prefix) as $line) {
            $script[] = [' ', $line];
        }
        foreach (self::lcs($midA, $midB) as $op) {
            $script[] = $op;
        }
        foreach (array_slice($a, count($a) - $suffix) as $line) {
            $script[] = [' ', $line];
        }

        return self::hunks($script, $label, $context);
    }

    /**
     * @param string[] $a
     * @param string[] $b
     * @return array<array{string, string}>
     */
    private static function lcs(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; --$i) {
            for ($j = $m - 1; $j >= 0; --$j) {
                $table[$i][$j] = $a[$i] === $b[$j] ? $table[$i + 1][$j + 1] + 1 : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }

        $ops = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = [' ', $a[$i]];
                ++$i;
                ++$j;
            } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $ops[] = ['-', $a[$i++]];
            } else {
                $ops[] = ['+', $b[$j++]];
            }
        }
        while ($i < $n) {
            $ops[] = ['-', $a[$i++]];
        }
        while ($j < $m) {
            $ops[] = ['+', $b[$j++]];
        }

        return $ops;
    }

    /** @param array<array{string, string}> $script */
    private static function hunks(array $script, string $label, int $context): string
    {
        $changed = array_keys(array_filter($script, static fn (array $op) => ' ' !== $op[0]));
        $out = "--- a/$label\n+++ b/$label\n";

        $groups = [];
        foreach ($changed as $index) {
            $start = max(0, $index - $context);
            $end = min(count($script) - 1, $index + $context);
            if ($groups && $start <= $groups[count($groups) - 1][1] + 1) {
                $groups[count($groups) - 1][1] = $end;
            } else {
                $groups[] = [$start, $end];
            }
        }

        foreach ($groups as [$start, $end]) {
            $lineA = 1;
            $lineB = 1;
            for ($k = 0; $k < $start; ++$k) {
                $lineA += '+' !== $script[$k][0] ? 1 : 0;
                $lineB += '-' !== $script[$k][0] ? 1 : 0;
            }
            $countA = $countB = 0;
            $body = '';
            for ($k = $start; $k <= $end; ++$k) {
                [$op, $line] = $script[$k];
                $countA += '+' !== $op ? 1 : 0;
                $countB += '-' !== $op ? 1 : 0;
                $body .= $op.$line."\n";
            }
            $out .= sprintf("@@ -%d,%d +%d,%d @@\n", $lineA, $countA, $lineB, $countB).$body;
        }

        return $out;
    }
}
