<?php

namespace Omnibridge\Composer\Tests;

use Omnibridge\Composer\Patch\Diff;
use PHPUnit\Framework\TestCase;

final class DiffTest extends TestCase
{
    public function testUnifiedDiffWithTwoHunks(): void
    {
        $before = implode("\n", range(1, 30))."\n";
        $after = str_replace(["\n5\n", "\n25\n"], ["\nfive\n", "\n25\nafter 25\n"], $before);

        $this->assertSame(<<<'DIFF'
            --- a/x/file.txt
            +++ b/x/file.txt
            @@ -2,7 +2,7 @@
             2
             3
             4
            -5
            +five
             6
             7
             8
            @@ -23,6 +23,7 @@
             23
             24
             25
            +after 25
             26
             27
             28

            DIFF, Diff::unified($before, $after, 'x/file.txt'));
    }

    public function testSameContentNoDiff(): void
    {
        $this->assertSame('', Diff::unified("a\n", "a\n", 'f'));
    }
}
