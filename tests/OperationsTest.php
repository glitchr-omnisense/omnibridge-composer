<?php

namespace Omnibridge\Composer\Tests;

use Omnibridge\Composer\Patch\InvalidDeclaration;
use Omnibridge\Composer\Patch\Operation\Operation;
use Omnibridge\Composer\Patch\Target;
use Omnibridge\Composer\Patch\TargetNotFound;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OperationsTest extends TestCase
{
    private const CODE = <<<'PHP'
        <?php

        namespace Acme\Clock;

        /**
         * A clock.
         *
         * @final
         */
        #[\Attribute]
        final class Clock
        {
            public const ZONE = 'UTC';
            private const FORMAT = 'H:i';

            private ?float $drift = null;

            public function __construct(private string $zone = 'UTC', ?float $timeout = 60)
            {
                $this->setTimeout($timeout);
            }

            private function setTimeout(?float $timeout): void
            {
            }

            function now(): string
            {
                return date(self::FORMAT);
            }
        }

        function tick(int $every = 1): void
        {
        }

        PHP;

    private static function target(): Target
    {
        return new Target('acme/clock', '1.0.0', 'src/Clock.php', '/tmp/Clock.php', 'acme/child:clock');
    }

    /** @param array<string, mixed> $declaration */
    private static function apply(array $declaration, string $code = self::CODE): string
    {
        return Operation::fromArray($declaration)->apply($code, self::target());
    }

    public function testReplaceEveryOccurrence(): void
    {
        $code = self::apply(['replace' => "'UTC'", 'with' => "'Europe/Paris'"]);
        $this->assertSame(2, substr_count($code, "'Europe/Paris'"));
        $this->assertStringNotContainsString("'UTC'", $code);
    }

    public function testReplaceCountsWhenAsked(): void
    {
        $this->expectException(TargetNotFound::class);
        $this->expectExceptionMessage('found 2 times');
        self::apply(['replace' => "'UTC'", 'with' => "'X'", 'count' => 1]);
    }

    public function testPrependIsMarkedAndIndented(): void
    {
        $code = self::apply(['prepend' => '$timeout ??= 60;', 'before' => '$this->setTimeout($timeout);']);
        $this->assertStringContainsString(
            "        // [omnibridge acme/child:clock]\n        \$timeout ??= 60;\n        \$this->setTimeout(\$timeout);",
            $code
        );
    }

    public function testAppendUnmarked(): void
    {
        $code = self::apply(['append' => 'echo 1;', 'after' => '$this->setTimeout($timeout);', 'mark' => false]);
        $this->assertStringContainsString("\$this->setTimeout(\$timeout);\n        echo 1;\n", $code);
        $this->assertStringNotContainsString('[omnibridge', $code);
    }

    public function testEraseKeepsTheLineAsAComment(): void
    {
        $code = self::apply(['erase' => 'private ?float $drift']);
        $this->assertStringContainsString('    // [omnibridge acme/child:clock] private ?float $drift = null;', $code);
    }

    public function testUnfinalRemovesTheKeywordAndTheTag(): void
    {
        $code = self::apply(['unfinal' => 'Acme\Clock\Clock']);
        $this->assertStringContainsString("#[\\Attribute]\nclass Clock\n", $code);
        $this->assertStringNotContainsString('@final', $code);
        $this->assertStringContainsString(" * A clock.\n *\n */", $code);
        $this->assertStringNotContainsString('final class', $code);
    }

    public function testUnfinalOfAClassThatIsNotFinal(): void
    {
        $this->expectException(TargetNotFound::class);
        $this->expectExceptionMessage('neither final nor @final');
        self::apply(['unfinal' => 'Acme\Clock\Clock'], self::apply(['unfinal' => 'Acme\Clock\Clock']));
    }

    public function testVisibilityOfAPromotedParameterAPropertyAMethodAConstant(): void
    {
        $code = self::apply(['visibility' => 'protected', 'of' => [
            'Acme\Clock\Clock::$zone',
            'Acme\Clock\Clock::$drift',
            'Acme\Clock\Clock::setTimeout()',
            'Acme\Clock\Clock::FORMAT',
        ]]);
        $this->assertStringContainsString('protected string $zone', $code);
        $this->assertStringContainsString('protected ?float $drift', $code);
        $this->assertStringContainsString('protected function setTimeout', $code);
        $this->assertStringContainsString("protected const FORMAT = 'H:i';", $code);
        $this->assertStringContainsString("public const ZONE = 'UTC';", $code);
    }

    public function testVisibilityOfEveryPrivateMember(): void
    {
        $code = self::apply(['visibility' => 'protected', 'of' => 'Acme\Clock\Clock::*']);
        $this->assertStringNotContainsString('private', $code);
        $this->assertSame(4, substr_count($code, 'protected'));
    }

    public function testVisibilityWhereItIsImplicit(): void
    {
        $code = self::apply(['visibility' => 'protected', 'of' => 'Acme\Clock\Clock::now()']);
        $this->assertStringContainsString('    protected function now(): string', $code);
    }

    public function testVisibilityAlreadyThere(): void
    {
        $this->expectException(TargetNotFound::class);
        $this->expectExceptionMessage('already public');
        self::apply(['visibility' => 'public', 'of' => 'Acme\Clock\Clock::ZONE']);
    }

    public function testDefaultOfAMethodAndOfAFunction(): void
    {
        $code = self::apply(['default' => 'null', 'of' => ['Acme\Clock\Clock::__construct($timeout)', 'Acme\Clock\tick($every)']]);
        $this->assertStringContainsString('?float $timeout = null)', $code);
        $this->assertStringContainsString('function tick(int $every = null)', $code);
        $this->assertStringContainsString("private string \$zone = 'UTC'", $code);
    }

    public function testDefaultAlreadyThere(): void
    {
        $this->expectException(TargetNotFound::class);
        $this->expectExceptionMessage("already defaults to 'UTC'");
        self::apply(['default' => "'UTC'", 'of' => 'Acme\Clock\Clock::__construct($zone)']);
    }

    public function testReturnTypeOfAMethodAndOfAFunction(): void
    {
        $code = self::apply(['returns' => 'never', 'of' => ['Acme\\Clock\\Clock::setTimeout()', 'Acme\\Clock\\tick()']]);
        $this->assertStringContainsString('private function setTimeout(?float $timeout): never', $code);
        $this->assertStringContainsString('function tick(int $every = 1): never', $code);
        $this->assertStringContainsString('    function now(): string', $code);
    }

    public function testReturnTypeAlreadyThere(): void
    {
        $this->expectException(TargetNotFound::class);
        $this->expectExceptionMessage('already returns string');
        self::apply(['returns' => 'string', 'of' => 'Acme\\Clock\\Clock::now()']);
    }

    public function testEverythingElseOfTheFileKeepsItsBytes(): void
    {
        $code = self::apply(['default' => 'null', 'of' => 'Acme\Clock\Clock::__construct($timeout)']);
        $this->assertSame(str_replace('$timeout = 60', '$timeout = null', self::CODE), $code);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function missingTargets(): iterable
    {
        yield 'replace' => [['replace' => 'nowhere', 'with' => 'x'], '"nowhere" not found'];
        yield 'prepend' => [['prepend' => 'x', 'before' => 'nowhere'], 'the line with "nowhere" not found'];
        yield 'erase' => [['erase' => 'nowhere'], 'not found'];
        yield 'unfinal' => [['unfinal' => 'Acme\Clock\Nowhere'], 'class Acme\Clock\Nowhere not found'];
        yield 'visibility' => [['visibility' => 'protected', 'of' => 'Acme\Clock\Clock::$nowhere'], 'Acme\Clock\Clock::$nowhere not found'];
        yield 'default' => [['default' => 'null', 'of' => 'Acme\Clock\Clock::now($x)'], 'has no parameter $x'];
        yield 'returns' => [['returns' => 'void', 'of' => 'Acme\Clock\Clock::__construct()'], 'declares no return type'];
    }

    /** @param array<string, mixed> $declaration */
    #[DataProvider('missingTargets')]
    public function testATargetNotFoundSaysWhat(array $declaration, string $message): void
    {
        $this->expectException(TargetNotFound::class);
        $this->expectExceptionMessage($message);
        self::apply($declaration);
    }

    public function testADeclarationSaysOneVerb(): void
    {
        $this->expectException(InvalidDeclaration::class);
        Operation::fromArray(['replace' => 'a', 'erase' => 'b', 'with' => 'c']);
    }

    public function testCodeThatDoesNotParse(): void
    {
        $this->expectException(TargetNotFound::class);
        $this->expectExceptionMessage('does not parse');
        self::apply(['unfinal' => 'A'], "<?php final class {");
    }
}
