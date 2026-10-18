<?php

namespace Omnibridge\Composer\Tests;

use Omnibridge\Composer\Inventory\Package;
use Omnibridge\Composer\Patch\Outcome;
use Omnibridge\Composer\Patch\Reconciler;
use Omnibridge\Composer\Patch\Record;
use Omnibridge\Composer\Tests\Fixtures\LowercaseZonePatch;
use PHPUnit\Framework\TestCase;

/**
 * The reconciler on a fake vendor directory: acme/clock (the target), and
 * packages that declare patches on it.
 */
final class ReconcilerTest extends TestCase
{
    private const CLOCK = <<<'PHP'
        <?php

        namespace Acme\Clock;

        final class Clock
        {
            public function __construct(private string $zone = 'UTC', ?float $timeout = 60)
            {
            }
        }

        PHP;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/omnibridge-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/vendor/acme/clock/src', 0777, true);
        file_put_contents($this->clockFile(), self::CLOCK);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function clockFile(): string
    {
        return $this->dir.'/vendor/acme/clock/src/Clock.php';
    }

    private function reconciler(): Reconciler
    {
        return new Reconciler($this->dir.'/vendor', $this->dir.'/vendor/composer/omnibridge');
    }

    private function clock(string $version = '1.2.0.0', string $pretty = '1.2.0'): Package
    {
        return new Package('acme/clock', $version, $pretty, $this->dir.'/vendor/acme/clock');
    }

    /** @param array<array<string, mixed>> $patches */
    private function declarer(array $patches, string $name = 'acme/child'): Package
    {
        return new Package($name, '1.0.0.0', '1.0.0', $this->dir.'/vendor/'.$name, ['omnibridge' => ['patches' => $patches]]);
    }

    private static function unfinal(array $extra = []): array
    {
        return $extra + [
            'id' => 'clock-open',
            'package' => 'acme/clock',
            'file' => 'src/Clock.php',
            'why' => 'The demo extends Clock.',
            'do' => [['unfinal' => 'Acme\Clock\Clock'], ['default' => 'null', 'of' => 'Acme\Clock\Clock::__construct($timeout)']],
        ];
    }

    private function record(): array
    {
        return json_decode((string) file_get_contents($this->dir.'/vendor/composer/omnibridge/patches.json'), true);
    }

    public function testApplyRecordsChecksumsAndKeepsThePublishedFile(): void
    {
        $report = $this->reconciler()->run([$this->clock(), $this->declarer([self::unfinal()])], '8.4.0');

        $this->assertSame([], $report->failures());
        $this->assertSame(['acme/clock/src/Clock.php'], $report->patched);
        $patched = (string) file_get_contents($this->clockFile());
        $this->assertStringContainsString("\nclass Clock\n", $patched);
        $this->assertStringContainsString('?float $timeout = null', $patched);

        $entry = $this->record()['files']['acme/clock/src/Clock.php'];
        $this->assertSame(Record::hash(self::CLOCK), $entry['before']);
        $this->assertSame(Record::hash($patched), $entry['after']);
        $this->assertSame(['acme/child:clock-open'], $entry['patches']);
        $this->assertSame('vendor/acme/clock/src/Clock.php', $entry['path']);
        $this->assertSame('1.2.0', $entry['version']);
        $this->assertSame(self::CLOCK, file_get_contents($this->dir.'/vendor/composer/omnibridge/originals/acme/clock/src/Clock.php'));
    }

    public function testApplyingAgainChangesNothing(): void
    {
        $packages = [$this->clock(), $this->declarer([self::unfinal()])];
        $this->reconciler()->run($packages, '8.4.0');
        $file = (string) file_get_contents($this->clockFile());
        $record = (string) file_get_contents($this->dir.'/vendor/composer/omnibridge/patches.json');

        $again = $this->reconciler()->run($packages, '8.4.0');

        $this->assertSame([], $again->patched);
        $this->assertSame([], $again->restored);
        $this->assertCount(1, $again->applied());
        $this->assertSame($file, file_get_contents($this->clockFile()));
        $this->assertSame($record, file_get_contents($this->dir.'/vendor/composer/omnibridge/patches.json'));
        $this->assertTrue($this->reconciler()->run($packages, '8.4.0', false)->isClean());
    }

    public function testRestoredWhenTheDeclaringPackageLeaves(): void
    {
        $this->reconciler()->run([$this->clock(), $this->declarer([self::unfinal()])], '8.4.0');

        $report = $this->reconciler()->run([$this->clock()], '8.4.0');

        $this->assertSame(['acme/clock/src/Clock.php'], $report->restored);
        $this->assertSame(self::CLOCK, file_get_contents($this->clockFile()));
        $this->assertDirectoryDoesNotExist($this->dir.'/vendor/composer/omnibridge');
    }

    public function testRestoredWhenTheVersionStopsMatching(): void
    {
        $declarer = $this->declarer([self::unfinal(['versions' => '^1.0'])]);
        $this->reconciler()->run([$this->clock(), $declarer], '8.4.0');

        $report = $this->reconciler()->run([$this->clock('2.0.0.0', '2.0.0'), $declarer], '8.4.0');

        $this->assertSame(Outcome::SKIPPED_VERSION, $report->outcomes[0]->status);
        $this->assertStringContainsString('acme/clock 2.0.0 does not match "^1.0"', $report->outcomes[0]->reason);
        $this->assertSame(self::CLOCK, file_get_contents($this->clockFile()));
    }

    public function testSkippedByPhp(): void
    {
        $report = $this->reconciler()->run([$this->clock(), $this->declarer([self::unfinal(['php' => '<8.4'])])], '8.4.22');

        $this->assertSame(Outcome::SKIPPED_PHP, $report->outcomes[0]->status);
        $this->assertSame('PHP 8.4.22 does not match "<8.4"', $report->outcomes[0]->reason);
        $this->assertSame(self::CLOCK, file_get_contents($this->clockFile()));
    }

    public function testStrictFailureSaysTheIdAndWhyAndTouchesNothing(): void
    {
        $patch = self::unfinal(['do' => ['replace' => 'nowhere', 'with' => 'x']]);
        $report = $this->reconciler()->run([$this->clock(), $this->declarer([$patch])], '8.4.0');

        $this->assertCount(1, $report->failures());
        $this->assertStringContainsString('acme/child:clock-open', $report->failureMessage());
        $this->assertStringContainsString('"nowhere" not found in src/Clock.php', $report->failureMessage());
        $this->assertStringContainsString('The demo extends Clock.', $report->failureMessage());
        $this->assertSame(self::CLOCK, file_get_contents($this->clockFile()));
    }

    public function testAnOptionalPatchThatFindsNothingIsSkipped(): void
    {
        $patch = self::unfinal(['optional' => true, 'do' => ['replace' => 'nowhere', 'with' => 'x']]);
        $report = $this->reconciler()->run([$this->clock(), $this->declarer([$patch])], '8.4.0');

        $this->assertSame([], $report->failures());
        $this->assertSame(Outcome::SKIPPED_OPTIONAL, $report->outcomes[0]->status);
    }

    public function testAClassPatch(): void
    {
        $patch = ['id' => 'lower', 'package' => 'acme/clock', 'file' => 'src/Clock.php', 'why' => 'Lower-case zones.', 'class' => LowercaseZonePatch::class];
        $report = $this->reconciler()->run([$this->clock(), $this->declarer([$patch])], '8.4.0');

        $this->assertSame([], $report->failures());
        $this->assertStringContainsString("\$zone = 'utc'", (string) file_get_contents($this->clockFile()));
    }

    public function testTwoPatchesOnOneFileInAFixedOrder(): void
    {
        $lower = ['id' => 'lower', 'package' => 'acme/clock', 'file' => 'src/Clock.php', 'why' => 'Lower-case zones.', 'class' => LowercaseZonePatch::class];
        $this->reconciler()->run([$this->clock(), $this->declarer([self::unfinal()]), $this->declarer([$lower], 'acme/other')], '8.4.0');

        $this->assertSame(['acme/child:clock-open', 'acme/other:lower'], $this->record()['files']['acme/clock/src/Clock.php']['patches']);

        // The first one leaves: the second stays, on the published file.
        $this->reconciler()->run([$this->clock(), $this->declarer([$lower], 'acme/other')], '8.4.0');
        $this->assertSame(str_replace("'UTC'", "'utc'", self::CLOCK), file_get_contents($this->clockFile()));
    }

    public function testAFileComposerReplacedIsPatchedAgain(): void
    {
        $packages = [$this->clock(), $this->declarer([self::unfinal()])];
        $this->reconciler()->run($packages, '8.4.0');

        // composer reinstall acme/clock, or an update to a release with a change.
        $released = str_replace('namespace Acme\Clock;', "namespace Acme\\Clock;\n\n// 1.2.1", self::CLOCK);
        file_put_contents($this->clockFile(), $released);
        $this->assertFalse($this->reconciler()->run($packages, '8.4.0', false)->isClean());

        $report = $this->reconciler()->run($packages, '8.4.0');

        $this->assertSame(['acme/clock/src/Clock.php'], $report->replaced);
        $this->assertStringContainsString('// 1.2.1', (string) file_get_contents($this->clockFile()));
        $this->assertStringContainsString("\nclass Clock\n", (string) file_get_contents($this->clockFile()));
        $this->assertSame(Record::hash($released), $this->record()['files']['acme/clock/src/Clock.php']['before']);
    }

    public function testCheckFailsWhenAFileDiffersFromTheRecord(): void
    {
        $packages = [$this->clock(), $this->declarer([self::unfinal()])];
        $this->reconciler()->run($packages, '8.4.0');
        file_put_contents($this->clockFile(), (string) file_get_contents($this->clockFile())."// edited by hand\n");

        $report = $this->reconciler()->run($packages, '8.4.0', false);

        $this->assertFalse($report->isClean());
        $this->assertStringContainsString('acme/clock/src/Clock.php differs from the record', implode("\n", $report->problems));
    }

    public function testCheckFailsWhenAPatchWasNeverApplied(): void
    {
        $report = $this->reconciler()->run([$this->clock(), $this->declarer([self::unfinal()])], '8.4.0', false);

        $this->assertFalse($report->isClean());
        $this->assertSame(self::CLOCK, file_get_contents($this->clockFile()));
    }

    public function testALinkedPackageIsNeverPatched(): void
    {
        mkdir($this->dir.'/source/src', 0777, true);
        file_put_contents($this->dir.'/source/src/Clock.php', self::CLOCK);
        exec('rm -rf '.escapeshellarg($this->dir.'/vendor/acme/clock'));
        symlink($this->dir.'/source', $this->dir.'/vendor/acme/clock');

        $report = $this->reconciler()->run([$this->clock(), $this->declarer([self::unfinal()])], '8.4.0');

        $this->assertSame(Outcome::SKIPPED_LINKED, $report->outcomes[0]->status);
        $this->assertSame(self::CLOCK, file_get_contents($this->dir.'/source/src/Clock.php'));
    }

    public function testAnAbsentPackageIsSkippedAndAnInvalidDeclarationFails(): void
    {
        $absent = self::unfinal(['id' => 'absent', 'package' => 'acme/none']);
        $invalid = ['id' => 'invalid', 'package' => 'acme/clock', 'file' => '../escape.php', 'why' => 'x', 'do' => ['erase' => 'x']];
        $report = $this->reconciler()->run([$this->clock(), $this->declarer([$absent, $invalid])], '8.4.0');

        $this->assertSame(Outcome::SKIPPED_ABSENT, $report->outcomes[1]->status);
        $this->assertSame('acme/child:invalid', $report->outcomes[0]->key);
        $this->assertStringContainsString('invalid declaration: "file" is relative to the target package', $report->outcomes[0]->reason);
    }

    public function testTheRootDeclaresToo(): void
    {
        $root = new Package('acme/project', '1.0.0.0', '1.0.0', $this->dir, ['omnibridge' => ['patches' => [self::unfinal()]]], [], true);
        $this->reconciler()->run([$this->clock(), $root], '8.4.0');

        $this->assertSame(['__root__:clock-open'], $this->record()['files']['acme/clock/src/Clock.php']['patches']);
    }

    public function testRestoreAll(): void
    {
        $this->reconciler()->run([$this->clock(), $this->declarer([self::unfinal()])], '8.4.0');

        $this->assertSame(['acme/clock/src/Clock.php'], $this->reconciler()->restoreAll());
        $this->assertSame(self::CLOCK, file_get_contents($this->clockFile()));
        $this->assertDirectoryDoesNotExist($this->dir.'/vendor/composer/omnibridge');
    }
}
