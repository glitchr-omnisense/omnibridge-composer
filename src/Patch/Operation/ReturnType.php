<?php

namespace Omnibridge\Composer\Patch\Operation;

use Omnibridge\Composer\Patch\InvalidDeclaration;
use Omnibridge\Composer\Patch\Target;
use Omnibridge\Composer\Patch\TargetNotFound;

/**
 * {"returns": "<type>", "of": "Class::method()" | "function()"} (or a list in
 * "of"): the declared return type replaced, written as PHP ("void",
 * "?string", "static"). A method without a declared return type, or whose
 * type already reads so, is not found.
 */
final class ReturnType extends Operation
{
    private const PATTERN = '/^(?:(?<class>[\\\\\w]+)::(?<method>\w+)|(?<function>[\\\\\w]+))\(\)$/';

    /** @param string[] $functions */
    public function __construct(public readonly string $type, public readonly array $functions)
    {
        foreach ($functions as $function) {
            if (!preg_match(self::PATTERN, $function)) {
                throw new InvalidDeclaration(sprintf('"of" names a method as Class::method() or a function as function(), not "%s"', $function));
            }
        }
    }

    protected static function declared(string $verb, array $declaration): self
    {
        return new self(self::string($declaration, 'returns'), self::strings($declaration, 'of'));
    }

    public function apply(string $code, Target $target): string
    {
        foreach ($this->functions as $function) {
            $code = $this->change($code, $function, $target);
        }

        return $code;
    }

    private function change(string $code, string $function, Target $target): string
    {
        preg_match(self::PATTERN, $function, $m);
        $source = new PhpSource($code, $target);
        $node = '' !== $m['class'] ? $source->findClass($m['class'])?->getMethod($m['method']) : $source->findFunction($m['function']);
        if (null === $node) {
            throw new TargetNotFound(sprintf('%s not found in %s', $function, $target->file));
        }

        $returnType = $node->getReturnType();
        if (null === $returnType) {
            throw new TargetNotFound(sprintf('%s declares no return type in %s', $function, $target->file));
        }

        $start = $returnType->getStartFilePos();
        $end = $returnType->getEndFilePos() + 1;
        if (substr($code, $start, $end - $start) === $this->type) {
            throw new TargetNotFound(sprintf('%s already returns %s in %s', $function, $this->type, $target->file));
        }

        return PhpSource::splice($code, [[$start, $end, $this->type]]);
    }

    public function describe(): string
    {
        return sprintf('%s return %s', implode(', ', $this->functions), $this->type);
    }
}
