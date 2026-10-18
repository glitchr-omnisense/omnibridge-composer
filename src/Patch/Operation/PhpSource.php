<?php

namespace Omnibridge\Composer\Patch\Operation;

use Omnibridge\Composer\Patch\Target;
use Omnibridge\Composer\Patch\TargetNotFound;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\Token;

/**
 * A PHP file read by nikic/php-parser to locate what an edit changes - a
 * class, a member, a parameter's default - by its name rather than by its
 * spelling. The edit itself is a splice at the node's offsets: the rest of
 * the file keeps its bytes (no pretty-printing).
 */
final class PhpSource
{
    /** @var Node[] */
    private array $ast;

    /** @var Token[] */
    private array $tokens;

    public function __construct(public readonly string $code, Target $target)
    {
        $parser = (new ParserFactory())->createForHostVersion();
        try {
            $ast = $parser->parse($code) ?? [];
        } catch (Error $e) {
            throw new TargetNotFound(sprintf('%s does not parse as PHP: %s', $target->file, $e->getMessage()));
        }

        $this->tokens = $parser->getTokens();
        $this->ast = (new NodeTraverser(new NameResolver()))->traverse($ast);
    }

    public function findClass(string $class): ?Node\Stmt\ClassLike
    {
        $class = ltrim($class, '\\');

        return (new NodeFinder())->findFirst($this->ast, static fn (Node $node) => $node instanceof Node\Stmt\ClassLike
            && isset($node->namespacedName)
            && 0 === strcasecmp($node->namespacedName->toString(), $class));
    }

    public function findFunction(string $function): ?Node\Stmt\Function_
    {
        $function = ltrim($function, '\\');

        return (new NodeFinder())->findFirst($this->ast, static fn (Node $node) => $node instanceof Node\Stmt\Function_
            && 0 === strcasecmp($node->namespacedName->toString(), $function));
    }

    /** @return Token[] */
    public function tokens(): array
    {
        return $this->tokens;
    }

    /**
     * The index of the first token from $from to $to (inclusive) whose id is
     * one of $ids, or null.
     *
     * @param int[] $ids
     */
    public function token(int $from, int $to, array $ids): ?int
    {
        for ($i = $from; $i <= $to && isset($this->tokens[$i]); ++$i) {
            if (in_array($this->tokens[$i]->id, $ids, true)) {
                return $i;
            }
        }

        return null;
    }

    /** The first token of a declaration after its attributes (#[...]) and the blanks that follow them. */
    public function afterAttributes(Node $node): int
    {
        $start = $node->getStartTokenPos();
        foreach ($node->attrGroups ?? [] as $group) {
            $start = max($start, $group->getEndTokenPos() + 1);
        }
        while (isset($this->tokens[$start]) && in_array($this->tokens[$start]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            ++$start;
        }

        return $start;
    }

    /**
     * $edits applied to $code: [start offset, end offset (exclusive), text],
     * from the last to the first so that offsets stay valid.
     *
     * @param array<array{int, int, string}> $edits
     */
    public static function splice(string $code, array $edits): string
    {
        usort($edits, static fn (array $a, array $b) => $b[0] <=> $a[0]);
        foreach ($edits as [$start, $end, $text]) {
            $code = substr($code, 0, $start).$text.substr($code, $end);
        }

        return $code;
    }
}
