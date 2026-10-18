<?php

namespace Omnibridge\Composer\Patch\Operation;

use Omnibridge\Composer\Patch\InvalidDeclaration;
use Omnibridge\Composer\Patch\Target;
use Omnibridge\Composer\Patch\TargetNotFound;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;

/**
 * {"visibility": "protected", "of": "Class::$property" | "Class::method()" |
 * "Class::CONSTANT" | "Class::*"} (or a list in "of"): the member's
 * visibility keyword changed - a promoted constructor parameter counts as a
 * property. "Class::*" changes every private member of the class. A member
 * that already has that visibility is not found.
 */
final class Visibility extends Operation
{
    private const KEYWORDS = ['public' => T_PUBLIC, 'protected' => T_PROTECTED, 'private' => T_PRIVATE];

    /** @param string[] $members */
    public function __construct(public readonly string $visibility, public readonly array $members)
    {
        if (!isset(self::KEYWORDS[$visibility])) {
            throw new InvalidDeclaration(sprintf('"visibility" is public, protected or private, not "%s"', $visibility));
        }
        foreach ($members as $member) {
            if (!preg_match('/^[\\\\\w]+::(\$\w+|\w+\(\)|\w+|\*)$/', $member)) {
                throw new InvalidDeclaration(sprintf('"of" names a member as Class::$property, Class::method(), Class::CONSTANT or Class::*, not "%s"', $member));
            }
        }
    }

    protected static function declared(string $verb, array $declaration): self
    {
        return new self(self::string($declaration, 'visibility'), self::strings($declaration, 'of'));
    }

    public function apply(string $code, Target $target): string
    {
        foreach ($this->members as $member) {
            $code = $this->change($code, $member, $target);
        }

        return $code;
    }

    private function change(string $code, string $member, Target $target): string
    {
        [$class, $name] = explode('::', $member, 2);
        $source = new PhpSource($code, $target);
        $node = $source->findClass($class);
        if (null === $node) {
            throw new TargetNotFound(sprintf('class %s not found in %s', $class, $target->file));
        }

        $members = '*' === $name ? $this->privateMembers($node) : array_filter([$this->member($node, $name)]);
        if (!$members) {
            throw new TargetNotFound('*' === $name
                ? sprintf('%s has no private member in %s', $class, $target->file)
                : sprintf('%s not found in %s', $member, $target->file));
        }

        $edits = [];
        foreach ($members as $declaration) {
            $edit = $this->edit($source, $declaration);
            if (null === $edit) {
                if ('*' !== $name) {
                    throw new TargetNotFound(sprintf('%s is already %s in %s', $member, $this->visibility, $target->file));
                }
                continue;
            }
            $edits[] = $edit;
        }

        return PhpSource::splice($code, $edits);
    }

    /** The node that declares $name in $class: Property, Param (promoted), ClassMethod or ClassConst. */
    private function member(Node\Stmt\ClassLike $class, string $name): ?Node
    {
        foreach ($class->stmts as $stmt) {
            if (str_starts_with($name, '$')) {
                if ($stmt instanceof Property) {
                    foreach ($stmt->props as $property) {
                        if ($property->name->toString() === substr($name, 1)) {
                            return $stmt;
                        }
                    }
                }
                if ($stmt instanceof ClassMethod && '__construct' === $stmt->name->toLowerString()) {
                    foreach ($stmt->params as $param) {
                        if ($param->flags && $param->var instanceof Node\Expr\Variable && $param->var->name === substr($name, 1)) {
                            return $param;
                        }
                    }
                }
            } elseif (str_ends_with($name, '()')) {
                if ($stmt instanceof ClassMethod && 0 === strcasecmp($stmt->name->toString(), substr($name, 0, -2))) {
                    return $stmt;
                }
            } elseif ($stmt instanceof ClassConst) {
                foreach ($stmt->consts as $const) {
                    if ($const->name->toString() === $name) {
                        return $stmt;
                    }
                }
            }
        }

        return null;
    }

    /** @return Node[] */
    private function privateMembers(Node\Stmt\ClassLike $class): array
    {
        $members = [];
        foreach ($class->stmts as $stmt) {
            if (($stmt instanceof Property || $stmt instanceof ClassMethod || $stmt instanceof ClassConst) && $stmt->isPrivate()) {
                $members[] = $stmt;
            }
            if ($stmt instanceof ClassMethod && '__construct' === $stmt->name->toLowerString()) {
                foreach ($stmt->params as $param) {
                    if ($param->flags & Node\Stmt\Class_::MODIFIER_PRIVATE) {
                        $members[] = $param;
                    }
                }
            }
        }

        return $members;
    }

    /**
     * The splice that gives $node the visibility: its keyword replaced, or
     * the keyword inserted where it is implicit (a method or constant without
     * one, a `var` property). Null when it already has it.
     *
     * @return array{int, int, string}|null
     */
    private function edit(PhpSource $source, Node $node): ?array
    {
        $tokens = $source->tokens();
        $from = $source->afterAttributes($node);
        $to = $node->getEndTokenPos();
        $keyword = $source->token($from, $to, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR]);

        // A keyword after the member's own name belongs to something inside it.
        $stop = $source->token($from, $to, [T_FUNCTION, T_CONST, T_VARIABLE]);
        if (null !== $keyword && null !== $stop && $keyword > $stop) {
            $keyword = null;
        }

        if (null !== $keyword) {
            if (self::KEYWORDS[$this->visibility] === $tokens[$keyword]->id) {
                return null;
            }

            return [$tokens[$keyword]->pos, $tokens[$keyword]->pos + strlen($tokens[$keyword]->text), $this->visibility];
        }

        if ('public' === $this->visibility) {
            return null;
        }

        return [$tokens[$from]->pos, $tokens[$from]->pos, $this->visibility.' '];
    }

    public function describe(): string
    {
        return sprintf('make %s %s', implode(', ', $this->members), $this->visibility);
    }
}
