<?php

namespace Omnibridge\Composer\Patch\Operation;

use Omnibridge\Composer\Patch\InvalidDeclaration;
use Omnibridge\Composer\Patch\Target;
use Omnibridge\Composer\Patch\TargetNotFound;

/**
 * One edit of the vocabulary. apply() returns the code changed, or throws
 * TargetNotFound - also when the edit would change nothing: a patch whose
 * target already reads as patched has become useless, and saying so is how a
 * maintainer learns to drop it or narrow its "versions".
 */
abstract class Operation
{
    /** The verbs, in the order the docs list them. */
    public const VERBS = [
        'replace' => Replace::class,
        'prepend' => Insert::class,
        'append' => Insert::class,
        'erase' => Erase::class,
        'unfinal' => Unfinal::class,
        'visibility' => Visibility::class,
        'default' => DefaultValue::class,
        'returns' => ReturnType::class,
    ];

    /** @throws TargetNotFound */
    abstract public function apply(string $code, Target $target): string;

    /** One line for the listing: what the edit does. */
    abstract public function describe(): string;

    /** @param array<string, mixed> $declaration */
    public static function fromArray(array $declaration): self
    {
        $verbs = array_values(array_intersect(array_keys(self::VERBS), array_keys($declaration)));
        if (1 !== count($verbs)) {
            throw new InvalidDeclaration(sprintf(
                'an operation says exactly one of %s; got %s',
                implode(', ', array_keys(self::VERBS)),
                json_encode($declaration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ));
        }

        $verb = $verbs[0];
        $class = self::VERBS[$verb];

        return $class::declared($verb, $declaration);
    }

    /** @param array<string, mixed> $declaration */
    abstract protected static function declared(string $verb, array $declaration): self;

    /** @param array<string, mixed> $declaration */
    protected static function string(array $declaration, string $key, bool $required = true): ?string
    {
        if (!array_key_exists($key, $declaration)) {
            if ($required) {
                throw new InvalidDeclaration(sprintf('"%s" is missing in %s', $key, json_encode($declaration, JSON_UNESCAPED_SLASHES)));
            }

            return null;
        }

        $value = $declaration[$key];
        if (is_array($value)) {
            $value = implode("\n", $value);
        }
        if (!is_string($value) || '' === $value) {
            throw new InvalidDeclaration(sprintf('"%s" must be a non-empty string', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $declaration
     * @return string[]
     */
    protected static function strings(array $declaration, string $key): array
    {
        $value = $declaration[$key] ?? null;
        $values = is_array($value) ? $value : [$value];
        foreach ($values as $item) {
            if (!is_string($item) || '' === $item) {
                throw new InvalidDeclaration(sprintf('"%s" must be a non-empty string or a list of them', $key));
            }
        }

        return array_values($values);
    }

    /** @param array<string, mixed> $declaration */
    protected static function count(array $declaration): ?int
    {
        if (!array_key_exists('count', $declaration)) {
            return null;
        }
        if (!is_int($declaration['count']) || $declaration['count'] < 1) {
            throw new InvalidDeclaration('"count" must be a positive integer');
        }

        return $declaration['count'];
    }

    protected static function expectCount(?int $expected, int $found, string $what, Target $target): void
    {
        if (0 === $found) {
            throw new TargetNotFound(sprintf('%s not found in %s', $what, $target->file));
        }
        if (null !== $expected && $expected !== $found) {
            throw new TargetNotFound(sprintf('%s found %d times in %s, the patch expects %d', $what, $found, $target->file, $expected));
        }
    }

    protected static function quote(string $text): string
    {
        $line = str_replace("\n", '\n', $text);

        return '"'.(strlen($line) > 70 ? substr($line, 0, 67).'...' : $line).'"';
    }
}
