<?php

namespace Omnibridge\Composer\Patch\Operation;

use Omnibridge\Composer\Patch\InvalidDeclaration;
use Omnibridge\Composer\Patch\Target;
use Omnibridge\Composer\Patch\TargetNotFound;
use PhpParser\Node;

/**
 * {"default": "<PHP expression>", "of": "Class::method($parameter)" |
 * "function($parameter)"} (or a list in "of"): the parameter's default value
 * replaced by the expression, written as PHP ("null", "60", "'fr'"). A
 * parameter without a default, or whose default already reads so, is not
 * found.
 */
final class DefaultValue extends Operation
{
    private const PATTERN = '/^(?:(?<class>[\\\\\w]+)::(?<method>\w+)|(?<function>[\\\\\w]+))\(\$(?<name>\w+)\)$/';

    /** @param string[] $parameters */
    public function __construct(public readonly string $value, public readonly array $parameters)
    {
        foreach ($parameters as $parameter) {
            if (!preg_match(self::PATTERN, $parameter)) {
                throw new InvalidDeclaration(sprintf('"of" names a parameter as Class::method($parameter) or function($parameter), not "%s"', $parameter));
            }
        }
    }

    protected static function declared(string $verb, array $declaration): self
    {
        $value = $declaration['default'] ?? null;
        if (!is_string($value) || '' === trim($value)) {
            throw new InvalidDeclaration('"default" is the new default value written as PHP, a non-empty string ("null", "60", "\'fr\'")');
        }

        return new self($value, self::strings($declaration, 'of'));
    }

    public function apply(string $code, Target $target): string
    {
        foreach ($this->parameters as $parameter) {
            $code = $this->change($code, $parameter, $target);
        }

        return $code;
    }

    private function change(string $code, string $parameter, Target $target): string
    {
        preg_match(self::PATTERN, $parameter, $m);
        $source = new PhpSource($code, $target);

        if ('' !== $m['class']) {
            $class = $source->findClass($m['class']);
            $function = $class?->getMethod($m['method']);
            $what = $m['class'].'::'.$m['method'].'()';
        } else {
            $function = $source->findFunction($m['function']);
            $what = $m['function'].'()';
        }
        if (null === $function) {
            throw new TargetNotFound(sprintf('%s not found in %s', $what, $target->file));
        }

        foreach ($function->getParams() as $param) {
            if (!$param->var instanceof Node\Expr\Variable || $param->var->name !== $m['name']) {
                continue;
            }
            if (null === $param->default) {
                throw new TargetNotFound(sprintf('$%s of %s has no default value in %s', $m['name'], $what, $target->file));
            }

            $start = $param->default->getStartFilePos();
            $end = $param->default->getEndFilePos() + 1;
            if (trim(substr($code, $start, $end - $start)) === trim($this->value)) {
                throw new TargetNotFound(sprintf('$%s of %s already defaults to %s in %s', $m['name'], $what, $this->value, $target->file));
            }

            return PhpSource::splice($code, [[$start, $end, $this->value]]);
        }

        throw new TargetNotFound(sprintf('%s has no parameter $%s in %s', $what, $m['name'], $target->file));
    }

    public function describe(): string
    {
        return sprintf('default %s = %s', implode(', ', $this->parameters), $this->value);
    }
}
