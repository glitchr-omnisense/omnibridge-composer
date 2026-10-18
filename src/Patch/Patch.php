<?php

namespace Omnibridge\Composer\Patch;

use Omnibridge\Composer\Patch\Operation\Operation;

/**
 * A patch as a package (or the root) declares it in extra.omnibridge.patches.
 *
 *     {
 *         "id": "process-timeout-from-env",
 *         "package": "symfony/process",
 *         "versions": "^6.0 || ^7.0 || ^8.0",
 *         "php": ">=8.2",
 *         "file": "Process.php",
 *         "why": "...",
 *         "upstream": "https://github.com/...",
 *         "optional": false,
 *         "do": [{"default": "null", "of": "Symfony\\Component\\Process\\Process::__construct($timeout)"}]
 *     }
 *
 * "do" is one operation or a list of them (the vocabulary: docs/patches.md);
 * "class" names a PatchInterface instead, for what the vocabulary cannot say.
 */
final class Patch
{
    /** @param Operation[] $operations */
    public function __construct(
        public readonly string $id,
        public readonly string $declaredBy,
        public readonly string $package,
        public readonly string $file,
        public readonly string $why,
        public readonly array $operations = [],
        public readonly ?string $class = null,
        public readonly ?string $versions = null,
        public readonly ?string $php = null,
        public readonly ?string $upstream = null,
        public readonly bool $optional = false,
    ) {
    }

    /** The patch's name in listings and in the record: <declaring package>:<id>. */
    public function key(): string
    {
        return $this->declaredBy.':'.$this->id;
    }

    /** @param array<string, mixed> $declaration */
    public static function fromArray(array $declaration, string $declaredBy): self
    {
        $string = static function (string $key, bool $required) use ($declaration): ?string {
            $value = $declaration[$key] ?? null;
            if (null === $value && !$required) {
                return null;
            }
            if (!is_string($value) || '' === trim($value)) {
                throw new InvalidDeclaration(sprintf('"%s" must be a non-empty string', $key));
            }

            return $value;
        };

        $id = $string('id', true);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $id)) {
            throw new InvalidDeclaration(sprintf('"id" is letters, digits, dots, dashes and underscores, not "%s"', $id));
        }

        $file = $string('file', true);
        if (str_starts_with($file, '/') || in_array('..', explode('/', $file), true)) {
            throw new InvalidDeclaration(sprintf('"file" is relative to the target package, inside it, not "%s"', $file));
        }

        $hasDo = array_key_exists('do', $declaration);
        $hasClass = array_key_exists('class', $declaration);
        if ($hasDo === $hasClass) {
            throw new InvalidDeclaration('a patch says either "do" (operations) or "class" (a PatchInterface), one of them');
        }

        $operations = [];
        if ($hasDo) {
            $do = $declaration['do'];
            if (!is_array($do) || [] === $do) {
                throw new InvalidDeclaration('"do" is an operation or a non-empty list of them');
            }
            foreach (array_is_list($do) ? $do : [$do] as $operation) {
                if (!is_array($operation)) {
                    throw new InvalidDeclaration('an operation is an object, such as {"replace": "...", "with": "..."}');
                }
                $operations[] = Operation::fromArray($operation);
            }
        }

        $patch = new self(
            id: $id,
            declaredBy: $declaredBy,
            package: $string('package', true),
            file: $file,
            why: $string('why', true),
            operations: $operations,
            class: $hasClass ? $string('class', true) : null,
            versions: $string('versions', false),
            php: $string('php', false),
            upstream: $string('upstream', false),
            optional: (bool) ($declaration['optional'] ?? false),
        );

        foreach (['versions', 'php'] as $constraint) {
            if (null !== $patch->{$constraint}) {
                try {
                    (new \Composer\Semver\VersionParser())->parseConstraints($patch->{$constraint});
                } catch (\UnexpectedValueException $e) {
                    throw new InvalidDeclaration(sprintf('"%s" is not a version constraint: %s', $constraint, $e->getMessage()));
                }
            }
        }

        return $patch;
    }

    /** What the patch does, one line per operation. */
    public function describe(): array
    {
        return null !== $this->class ? ['class '.$this->class] : array_map(static fn (Operation $o) => $o->describe(), $this->operations);
    }
}
