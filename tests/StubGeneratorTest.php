<?php

namespace Omnibridge\Composer\Tests;

use Omnibridge\Composer\Stub\AbstractStub;
use Omnibridge\Composer\Stub\StubContext;
use Omnibridge\Composer\Stub\StubGenerator;
use PHPUnit\Framework\TestCase;

final class StubGeneratorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/omnibridge-stubs-'.bin2hex(random_bytes(4));
        $classes = [
            'User.php' => "<?php\n\nnamespace Base\\Entity;\n\n#[\\Attribute]\nclass User\n{\n}\n",
            'Thread/Tag.php' => "<?php\nnamespace Base\\Entity\\Thread;\nclass Tag extends \\stdClass {}\n",
            'Thread/Mention.php' => "<?php\nnamespace Base\\Entity\\Thread;\nclass Mention {}\n",
            'AbstractThing.php' => "<?php\nnamespace Base\\Entity;\nabstract class AbstractThing {}\n",
            'Sealed.php' => "<?php\nnamespace Base\\Entity;\nfinal class Sealed {}\n",
            'Value.php' => "<?php\nnamespace Base\\Entity;\nreadonly class Value {}\n",
            'Named.php' => "<?php\nnamespace Base\\Entity;\ninterface Named {}\n",
            'Owned.php' => "<?php\nnamespace Base\\Entity;\ntrait Owned { public function x() { return static::class; } }\n",
            'Kind.php' => "<?php\nnamespace Base\\Entity;\nenum Kind { case A; }\n",
            'notes.txt' => "not PHP\n",
        ];
        foreach ($classes as $file => $code) {
            $path = $this->dir.'/vendor/acme/base/src/Entity/'.$file;
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $code);
        }
        // The application defines its own App\Entity\Thread\Mention.
        mkdir($this->dir.'/src/Entity/Thread', 0777, true);
        file_put_contents($this->dir.'/src/Entity/Thread/Mention.php', "<?php\nnamespace App\\Entity\\Thread;\nclass Mention extends \\Base\\Entity\\Thread\\Mention {}\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function provider(): AbstractStub
    {
        return new class extends AbstractStub {
            public function getName(): string
            {
                return 'Entity';
            }

            protected function getSourceNamespace(): string
            {
                return 'Base\Entity';
            }

            protected function getSourceDirectory(StubContext $context): string
            {
                return $context->packageDir.'/src/Entity';
            }

            protected function getTargetNamespace(): string
            {
                return 'App\Entity';
            }

            protected function getApplicationDirectory(StubContext $context): ?string
            {
                return $context->projectDir.'/src/Entity';
            }
        };
    }

    private function generate(bool $write = true): \Omnibridge\Composer\Stub\StubReport
    {
        $context = new StubContext($this->dir, $this->dir.'/vendor', $this->dir.'/vendor/acme/base', 'acme/base');

        return (new StubGenerator($this->dir.'/vendor/composer/omnibridge/stubs'))->run([[$this->provider(), $context]], $write);
    }

    public function testPlainClassesWithoutAnApplicationClassGetAStub(): void
    {
        $report = $this->generate();

        $this->assertSame(['Entity' => 2], $report->counts);
        $this->assertSame(['Entity/Thread/Tag.php', 'Entity/User.php'], $report->written);

        $stub = (string) file_get_contents($this->dir.'/vendor/composer/omnibridge/stubs/Entity/Thread/Tag.php');
        $this->assertStringContainsString("namespace App\\Entity\\Thread;\n", $stub);
        $this->assertStringContainsString("if (!class_exists('\\App\\Entity\\Thread\\Tag')) {\n    class Tag extends \\Base\\Entity\\Thread\\Tag {}\n}\n", $stub);
        $this->assertStringNotContainsString($this->dir, $stub);
    }

    public function testDeterministicAndChecked(): void
    {
        $this->generate();
        $before = (string) file_get_contents($this->dir.'/vendor/composer/omnibridge/stubs/Entity/User.php');

        $again = $this->generate();
        $this->assertSame([], $again->written);
        $this->assertSame($before, file_get_contents($this->dir.'/vendor/composer/omnibridge/stubs/Entity/User.php'));
        $this->assertSame([], $this->generate(false)->problems);

        file_put_contents($this->dir.'/vendor/composer/omnibridge/stubs/Entity/User.php', 'edited');
        $this->assertSame(['Entity/User.php is not what its provider generates'], $this->generate(false)->problems);
    }

    public function testAStubWhoseClassIsGoneIsRemoved(): void
    {
        $this->generate();
        unlink($this->dir.'/vendor/acme/base/src/Entity/Thread/Tag.php');

        $report = $this->generate();

        $this->assertSame(['Entity/Thread/Tag.php'], $report->removed);
        $this->assertDirectoryDoesNotExist($this->dir.'/vendor/composer/omnibridge/stubs/Entity/Thread');
    }

    public function testNoProviderRemovesThemAll(): void
    {
        $this->generate();
        (new StubGenerator($this->dir.'/vendor/composer/omnibridge/stubs'))->run([]);

        $this->assertDirectoryDoesNotExist($this->dir.'/vendor/composer/omnibridge/stubs');
    }
}
