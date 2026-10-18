<?php

namespace Omnibridge\Composer\Tests\Scenario;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The example, as `docker compose run --rm example` runs it: real Composer
 * installs a project, requires examples/child-plugin's child, which patches
 * acme/greeter and declares a stub provider; checks, installs again, removes
 * the child. Runs in the suite's container (the engine at /engine, composer,
 * the network for nikic/php-parser).
 */
#[Group('scenario')]
final class ChildPluginTest extends TestCase
{
    public function testTheExampleFromStartToEnd(): void
    {
        if (!is_file('/engine/examples/child-plugin/run.sh') || '' === trim((string) shell_exec('command -v composer'))) {
            $this->markTestSkipped('Runs in the suite\'s container: docker compose run --rm test');
        }

        $work = sys_get_temp_dir().'/child-plugin-scenario-'.bin2hex(random_bytes(4));
        exec('WORK='.escapeshellarg($work).' sh /engine/examples/child-plugin/run.sh 2>&1', $lines, $status);
        exec('rm -rf '.escapeshellarg($work));
        $output = implode("\n", $lines);

        $this->assertSame(0, $status, $output);

        // Before the child: as published.
        $before = strstr($output, '== 2.', true);
        $this->assertStringContainsString('greet("world"): Hello, world.', $before);
        $this->assertStringContainsString('Greeter is final', $before);

        // The patch, its diff, the stub, the program with the patch, the checks.
        $this->assertStringContainsString('applied   acme/child-plugin:greeter-in-french', $output);
        $this->assertStringContainsString("-final class Greeter\n+class Greeter", $output);
        $this->assertStringContainsString("+    public function __construct(private string \$greeting = 'Bonjour')", $output);
        $this->assertStringContainsString('vendor/composer/omnibridge/stubs/Greeter/Greeter.php', $output);
        $this->assertStringContainsString('class Greeter extends \Acme\Greeter\Greeter {}', $output);
        $this->assertStringContainsString('greet("world"): BONJOUR, WORLD.', $output);
        $this->assertStringContainsString('omnibridge:patches --check: 1 patch(es) applied as recorded', $output);
        $this->assertStringContainsString('omnibridge:stubs --check: Greeter 2', $output);

        // Idempotent, then taken back.
        $this->assertStringContainsString('identical, byte for byte', $output);
        $after = strstr($output, '== 4.');
        $this->assertStringContainsString('acme/greeter/src/Greeter.php written back as published', $after);
        $this->assertStringContainsString('greet("world"): Hello, world.', $after);
        $this->assertStringContainsString('vendor/acme/greeter: as published', $after);
        $this->assertStringContainsString('vendor/composer/omnibridge: gone', $after);
    }
}
