<?php

namespace Omnibridge\Composer;

use Composer\Autoload\ClassLoader;
use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Omnibridge\Composer\Inventory\Package;
use Omnibridge\Composer\Patch\Patch;
use Omnibridge\Composer\Patch\PatchInterface;
use Omnibridge\Composer\Patch\Reconciler;
use Omnibridge\Composer\Patch\Report;
use Omnibridge\Composer\Patch\TargetNotFound;
use Omnibridge\Composer\Stub\StubContext;
use Omnibridge\Composer\Stub\StubGenerator;
use Omnibridge\Composer\Stub\StubInterface;
use Omnibridge\Composer\Stub\StubReport;

/**
 * The engine inside Composer: the installed packages read from Composer's
 * local repository, the reconciler and the stub generator run on them.
 */
final class Engine
{
    public const STATE_DIR = 'composer/omnibridge';

    public function __construct(private readonly Composer $composer, private readonly IOInterface $io)
    {
    }

    public function vendorDir(): string
    {
        return rtrim((string) $this->composer->getConfig()->get('vendor-dir'), '/');
    }

    public function projectDir(): string
    {
        $file = Factory::getComposerFile();

        return dirname((string) (realpath($file) ?: $file));
    }

    public function stateDir(): string
    {
        return $this->vendorDir().'/'.self::STATE_DIR;
    }

    /** The PHP version the project installs for: config.platform.php, else the running PHP. */
    public function php(): string
    {
        $platform = $this->composer->getConfig()->get('platform');

        return is_array($platform) && is_string($platform['php'] ?? null) ? $platform['php'] : PHP_VERSION;
    }

    /** @return Package[] the installed packages and the root */
    public function packages(): array
    {
        $installer = $this->composer->getInstallationManager();
        $packages = [];
        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages() as $package) {
            $path = $installer->getInstallPath($package);
            if (null === $path || '' === $path) {
                continue;
            }
            $packages[] = self::package($package, $path, false);
        }

        $packages[] = self::package($this->composer->getPackage(), $this->projectDir(), true);

        return $packages;
    }

    public function reconciler(): Reconciler
    {
        $packages = [];
        foreach ($this->packages() as $package) {
            $packages[$package->root ? Package::ROOT : $package->name] = $package;
        }

        return new Reconciler($this->vendorDir(), $this->stateDir(), function (Patch $patch) use ($packages): PatchInterface {
            $declarer = $packages[$patch->declaredBy] ?? null;

            // The declaring package's classes stay loadable while the patch runs.
            return new class($patch, $declarer) implements PatchInterface {
                public function __construct(private readonly Patch $patch, private readonly ?Package $declarer)
                {
                }

                public function apply(string $code, \Omnibridge\Composer\Patch\Target $target): string
                {
                    return Engine::runWith($this->declarer, function () use ($code, $target): string {
                        $class = (string) $this->patch->class;
                        if (!class_exists($class)) {
                            throw new TargetNotFound(sprintf('class %s not found in %s', $class, $this->patch->declaredBy));
                        }
                        $instance = new $class();
                        if (!$instance instanceof PatchInterface) {
                            throw new TargetNotFound(sprintf('%s does not implement %s', $class, PatchInterface::class));
                        }

                        return $instance->apply($code, $target);
                    });
                }
            };
        });
    }

    public function patch(bool $write = true): Report
    {
        return $this->reconciler()->run($this->packages(), $this->php(), $write);
    }

    public function stubs(bool $write = true): StubReport
    {
        $declarers = array_values(array_filter($this->packages(), static fn (Package $p) => [] !== $p->declares('stubs')));

        // The declaring packages' classes stay loadable while their providers run.
        return self::withAutoloads($declarers, function () use ($declarers, $write): StubReport {
            $providers = [];
            $problems = [];
            foreach ($declarers as $package) {
                foreach ($package->declares('stubs') as $class) {
                    try {
                        if (!is_string($class) || !class_exists($class)) {
                            throw new \RuntimeException(sprintf('class %s not found', json_encode($class)));
                        }
                        $provider = new $class();
                    } catch (\Throwable $e) {
                        $problems[] = sprintf('%s: %s', $package->name, $e->getMessage());
                        continue;
                    }
                    if (!$provider instanceof StubInterface) {
                        $problems[] = sprintf('%s: %s does not implement %s', $package->name, $class, StubInterface::class);
                        continue;
                    }
                    $providers[] = [$provider, new StubContext($this->projectDir(), $this->vendorDir(), $package->path, $package->name)];
                }
            }

            $report = (new StubGenerator($this->stateDir().'/stubs'))->run($providers, $write);
            array_unshift($report->problems, ...$problems);

            return $report;
        });
    }

    /** @return string[] */
    public function restoreAll(): array
    {
        $restored = (new Reconciler($this->vendorDir(), $this->stateDir()))->restoreAll();
        (new StubGenerator($this->stateDir().'/stubs'))->run([], true);
        if (is_dir($this->stateDir()) && [] === array_diff((array) scandir($this->stateDir()), ['.', '..'])) {
            rmdir($this->stateDir());
        }

        return $restored;
    }

    private static function package(PackageInterface $package, string $path, bool $root): Package
    {
        return new Package(
            name: $package->getName(),
            version: $package->getVersion(),
            prettyVersion: $package->getPrettyVersion(),
            path: $path,
            extra: $package->getExtra(),
            autoload: $package->getAutoload(),
            root: $root,
        );
    }

    /**
     * Runs $run with the declaring package's classes autoloadable (its psr-4,
     * psr-0 and classmap; never its "files", which would run its code inside
     * Composer). The loader comes after Composer's own and is removed after.
     *
     * @template T
     * @param callable(): T $run
     * @return T
     */
    public static function runWith(?Package $package, callable $run): mixed
    {
        return self::withAutoloads(null === $package ? [] : [$package], $run);
    }

    /**
     * @template T
     * @param Package[] $packages
     * @param callable(): T $run
     * @return T
     */
    private static function withAutoloads(array $packages, callable $run): mixed
    {
        $loader = new ClassLoader();
        foreach ($packages as $package) {
            $base = rtrim($package->path, '/').'/';
            foreach ($package->autoload['psr-4'] ?? [] as $prefix => $paths) {
                $loader->addPsr4($prefix, array_map(static fn (string $p) => $base.$p, (array) $paths));
            }
            foreach ($package->autoload['psr-0'] ?? [] as $prefix => $paths) {
                $loader->add($prefix, array_map(static fn (string $p) => $base.$p, (array) $paths));
            }
            foreach ($package->autoload['classmap'] ?? [] as $path) {
                if (is_file($base.$path)) {
                    foreach (\Composer\ClassMapGenerator\ClassMapGenerator::createMap($base.$path) as $class => $file) {
                        $loader->addClassMap([$class => $file]);
                    }
                }
            }
        }

        $loader->register(false);
        try {
            return $run();
        } finally {
            $loader->unregister();
        }
    }
}
