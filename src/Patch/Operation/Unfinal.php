<?php

namespace Omnibridge\Composer\Patch\Operation;

use Omnibridge\Composer\Patch\Target;
use Omnibridge\Composer\Patch\TargetNotFound;
use PhpParser\Node\Stmt\Class_;

/**
 * {"unfinal": "Vendor\\Package\\Class"} (or a list): the class's `final`
 * keyword and the `@final` line of its docblock removed - the one that
 * Symfony's DebugClassLoader reads. A class that is neither is not found.
 */
final class Unfinal extends Operation
{
    /** @param string[] $classes */
    public function __construct(public readonly array $classes)
    {
    }

    protected static function declared(string $verb, array $declaration): self
    {
        return new self(self::strings($declaration, 'unfinal'));
    }

    public function apply(string $code, Target $target): string
    {
        foreach ($this->classes as $class) {
            $code = $this->unfinal($code, $class, $target);
        }

        return $code;
    }

    private function unfinal(string $code, string $class, Target $target): string
    {
        $source = new PhpSource($code, $target);
        $node = $source->findClass($class);
        if (!$node instanceof Class_) {
            throw new TargetNotFound(sprintf('class %s not found in %s', $class, $target->file));
        }

        $edits = [];
        if ($node->isFinal()) {
            $tokens = $source->tokens();
            $final = $source->token($source->afterAttributes($node), $node->getEndTokenPos(), [T_FINAL]);
            if (null !== $final) {
                $next = $final + 1;
                while (isset($tokens[$next]) && T_WHITESPACE === $tokens[$next]->id) {
                    ++$next;
                }
                $edits[] = [$tokens[$final]->pos, $tokens[$next]->pos, ''];
            }
        }

        $doc = $node->getDocComment();
        if (null !== $doc && preg_match('/^[ \t]*\*[ \t]*@final\b[^\n]*\n/m', $doc->getText(), $match, PREG_OFFSET_CAPTURE)) {
            $start = $doc->getStartFilePos() + $match[0][1];
            $edits[] = [$start, $start + strlen($match[0][0]), ''];
        }

        if (!$edits) {
            throw new TargetNotFound(sprintf('class %s is neither final nor @final in %s', $class, $target->file));
        }

        return PhpSource::splice($code, $edits);
    }

    public function describe(): string
    {
        return 'unfinal '.implode(', ', $this->classes);
    }
}
