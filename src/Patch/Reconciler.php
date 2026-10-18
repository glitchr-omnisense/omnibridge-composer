<?php

namespace Omnibridge\Composer\Patch;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use Omnibridge\Composer\Inventory\Package;

/**
 * Brings vendor/ to the state the declarations describe, knowing no package.
 *
 * Every file a patch touches is computed from its published content - the
 * original the record kept, or the file itself when Composer has just
 * written it - with every applicable patch applied in order. A file that no
 * patch applies to any more (its declaring package left, its "versions" or
 * "php" stopped matching) is written back as published. So applying twice
 * changes nothing, and the record says, file by file, what vendor/ holds.
 */
final class Reconciler
{
    /** @var \Closure(Patch): PatchInterface */
    private \Closure $instantiate;

    /**
     * @param string $vendorDir  the project's vendor directory, absolute
     * @param string $stateDir   where the record lives (vendor/composer/omnibridge)
     * @param null|callable(Patch): PatchInterface $instantiate  builds a "class" patch (the engine loads it from its declaring package)
     */
    public function __construct(
        private readonly string $vendorDir,
        private readonly string $stateDir,
        ?callable $instantiate = null,
    ) {
        $this->instantiate = \Closure::fromCallable($instantiate ?? static function (Patch $patch): PatchInterface {
            $class = (string) $patch->class;
            if (!class_exists($class)) {
                throw new TargetNotFound(sprintf('class %s not found', $class));
            }
            $instance = new $class();
            if (!$instance instanceof PatchInterface) {
                throw new TargetNotFound(sprintf('%s does not implement %s', $class, PatchInterface::class));
            }

            return $instance;
        });
    }

    /**
     * @param Package[] $packages  the installed packages, the root among them
     * @param string    $php       the PHP version the project installs for (config.platform.php, else the running one)
     * @param bool      $write     false: compute and compare only (listing, --check)
     */
    public function run(array $packages, string $php, bool $write = true): Report
    {
        $report = new Report();
        $record = new Record($this->stateDir);
        $byName = [];
        foreach ($packages as $package) {
            $byName[$package->name] = $package;
        }

        /** @var array<string, array{path: string, package: Package|null, file: string, pristine: string|null, current: string|null, patches: Patch[]}> $files */
        $files = [];

        // The files patched before: their published content, from the record
        // while the file is still the one patched, else the file itself (Composer
        // replaced it: a reinstall or an update).
        foreach ($record->files() as $key => $entry) {
            $path = $this->absolute($entry['path']);
            if (!is_file($path)) {
                $files[$key] = ['path' => $path, 'package' => null, 'file' => $entry['file'], 'pristine' => null, 'current' => null, 'patches' => []];
                continue;
            }

            $current = (string) file_get_contents($path);
            if (Record::hash($current) === $entry['after']) {
                $pristine = $record->original($key);
                if (null === $pristine) {
                    $report->problems[] = sprintf('%s: the published copy the record keeps is missing or altered; the file is taken as it is', $key);
                    $pristine = $current;
                }
            } else {
                $pristine = $current;
                $report->replaced[] = $key;
                if (!$write) {
                    $report->problems[] = sprintf('%s differs from the record (%s expected, %s found)', $key, $entry['after'], Record::hash($current));
                }
            }

            $files[$key] = ['path' => $path, 'package' => $byName[$entry['package']] ?? null, 'file' => $entry['file'], 'pristine' => $pristine, 'current' => $current, 'patches' => []];
        }

        foreach ($this->declarations($packages, $report) as $patch) {
            $package = $byName[$patch->package] ?? null;
            if (null === $package || $package->root) {
                $report->outcomes[] = new Outcome($patch->key(), Outcome::SKIPPED_ABSENT, $patch->package.' is not installed', $patch);
                continue;
            }
            if (null !== $patch->versions && !self::matches($patch->versions, $package->version)) {
                $report->outcomes[] = new Outcome($patch->key(), Outcome::SKIPPED_VERSION, sprintf('%s %s does not match "%s"', $package->name, $package->prettyVersion, $patch->versions), $patch);
                continue;
            }
            if (null !== $patch->php && !self::matches($patch->php, (new VersionParser())->normalize($php))) {
                $report->outcomes[] = new Outcome($patch->key(), Outcome::SKIPPED_PHP, sprintf('PHP %s does not match "%s"', $php, $patch->php), $patch);
                continue;
            }
            if ($package->isLinked()) {
                $report->outcomes[] = new Outcome($patch->key(), Outcome::SKIPPED_LINKED, sprintf('%s is installed as a symbolic link (a path repository): patching it would change its source', $package->name), $patch);
                continue;
            }

            $key = $package->name.'/'.$patch->file;
            $path = rtrim($package->path, '/').'/'.$patch->file;
            if (!isset($files[$key]) || null === $files[$key]['pristine']) {
                if (!is_file($path)) {
                    $report->outcomes[] = $this->notFound($patch, sprintf('%s has no file %s', $package->name, $patch->file));
                    continue;
                }
                $current = (string) file_get_contents($path);
                $files[$key] = ['path' => $path, 'package' => $package, 'file' => $patch->file, 'pristine' => $current, 'current' => $current, 'patches' => []];
            }
            $files[$key]['package'] = $package;
            $files[$key]['patches'][] = $patch;
        }

        ksort($files);
        foreach ($files as $key => $state) {
            if (null === $state['pristine']) {
                // A recorded file that is gone: its package left with it.
                if ($write) {
                    $record->remove($key);
                } else {
                    $report->problems[] = sprintf('%s is in the record but no longer installed', $key);
                }
                continue;
            }

            $code = $state['pristine'];
            $applied = [];
            foreach ($state['patches'] as $patch) {
                $target = new Target($patch->package, $state['package']->prettyVersion, $patch->file, $state['path'], $patch->key());
                try {
                    $changed = $this->apply($patch, $code, $target);
                } catch (TargetNotFound $e) {
                    $report->outcomes[] = $this->notFound($patch, $e->getMessage());
                    continue;
                }
                $code = $changed;
                $applied[] = $patch->key();
                $report->outcomes[] = new Outcome($patch->key(), Outcome::APPLIED, implode('; ', $patch->describe()), $patch);
            }

            $desired = [] === $applied ? $state['pristine'] : $code;
            if ($state['current'] !== $desired) {
                if ($write) {
                    file_put_contents($state['path'], $desired);
                } else {
                    $report->problems[] = sprintf('%s is not in the declared state (%s)', $key, [] === $applied ? 'published content expected' : implode(', ', $applied));
                }
                if ([] === $applied) {
                    $report->restored[] = $key;
                } else {
                    $report->patched[] = $key;
                }
            }

            if ([] === $applied) {
                if ($write) {
                    $record->remove($key);
                } elseif (null !== $record->get($key)) {
                    $report->problems[] = sprintf('%s is in the record but no patch applies to it any more', $key);
                }
                continue;
            }

            if ($write) {
                $record->set($key, $state['package']->name, $state['package']->prettyVersion, $state['file'], $this->relative($state['path']), $state['pristine'], $desired, $applied);
            } else {
                $entry = $record->get($key);
                if (null === $entry) {
                    $report->problems[] = sprintf('%s is not in the record (patches never applied: run `composer omnibridge:patches --apply` or `composer install`)', $key);
                } elseif ($entry['patches'] !== $applied || $entry['after'] !== Record::hash($desired)) {
                    $report->problems[] = sprintf('%s: the record says %s, the declarations give %s', $key, implode(', ', $entry['patches']), implode(', ', $applied));
                }
            }
        }

        if ($write) {
            $record->save();
        }

        return $report;
    }

    /**
     * Every recorded file written back as published, the record removed: what
     * the engine does when it is uninstalled.
     *
     * @return string[] the files restored
     */
    public function restoreAll(): array
    {
        $record = new Record($this->stateDir);
        $restored = [];
        foreach ($record->files() as $key => $entry) {
            $path = $this->absolute($entry['path']);
            $original = $record->original($key);
            if (null !== $original && is_file($path) && Record::hash((string) file_get_contents($path)) === $entry['after']) {
                file_put_contents($path, $original);
                $restored[] = $key;
            }
            $record->remove($key);
        }
        $record->save();

        return $restored;
    }

    /**
     * The published and the current content of each recorded file, for --diff.
     *
     * @return array<string, array{string, string}>
     */
    public function recordedChanges(): array
    {
        $record = new Record($this->stateDir);
        $changes = [];
        foreach ($record->files() as $key => $entry) {
            $path = $this->absolute($entry['path']);
            $changes[$key] = [(string) $record->original($key), is_file($path) ? (string) file_get_contents($path) : ''];
        }

        return $changes;
    }

    /**
     * The patches the packages declare, in a fixed order: packages by name,
     * the root last, each package's in its order.
     *
     * @param Package[] $packages
     * @return Patch[]
     */
    private function declarations(array $packages, Report $report): array
    {
        usort($packages, static fn (Package $a, Package $b) => [$a->root, $a->name] <=> [$b->root, $b->name]);

        $patches = [];
        foreach ($packages as $package) {
            foreach ($package->declares('patches') as $index => $declaration) {
                $declaredBy = $package->root ? Package::ROOT : $package->name;
                try {
                    if (!is_array($declaration)) {
                        throw new InvalidDeclaration('a patch is an object');
                    }
                    $patch = Patch::fromArray($declaration, $declaredBy);
                } catch (InvalidDeclaration $e) {
                    $id = is_array($declaration) && is_string($declaration['id'] ?? null) ? $declaration['id'] : '#'.$index;
                    $report->outcomes[] = new Outcome($declaredBy.':'.$id, Outcome::FAILED, 'invalid declaration: '.$e->getMessage());
                    continue;
                }
                if (isset($patches[$patch->key()])) {
                    $report->outcomes[] = new Outcome($patch->key(), Outcome::FAILED, 'declared twice', $patch);
                    continue;
                }
                $patches[$patch->key()] = $patch;
            }
        }

        return array_values($patches);
    }

    private function apply(Patch $patch, string $code, Target $target): string
    {
        if (null !== $patch->class) {
            try {
                $changed = ($this->instantiate)($patch)->apply($code, $target);
            } catch (TargetNotFound $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new TargetNotFound(sprintf('%s: %s', $patch->class, $e->getMessage()), 0, $e);
            }
            if ($changed === $code) {
                throw new TargetNotFound(sprintf('%s changed nothing in %s', $patch->class, $target->file));
            }

            return $changed;
        }

        foreach ($patch->operations as $operation) {
            $code = $operation->apply($code, $target);
        }

        return $code;
    }

    private function notFound(Patch $patch, string $reason): Outcome
    {
        return new Outcome($patch->key(), $patch->optional ? Outcome::SKIPPED_OPTIONAL : Outcome::FAILED, $reason, $patch);
    }

    private static function matches(string $constraint, string $version): bool
    {
        $parser = new VersionParser();

        return $parser->parseConstraints($constraint)->matches(new Constraint('==', $version));
    }

    private function relative(string $path): string
    {
        $vendor = rtrim($this->vendorDir, '/').'/';

        return str_starts_with($path, $vendor) ? 'vendor/'.substr($path, strlen($vendor)) : $path;
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, 'vendor/') ? rtrim($this->vendorDir, '/').'/'.substr($path, 7) : $path;
    }
}
