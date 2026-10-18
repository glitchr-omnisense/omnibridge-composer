<?php

namespace Omnibridge\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Omnibridge\Composer\Command\CommandProvider;
use Omnibridge\Composer\Patch\Outcome;
use Omnibridge\Composer\Patch\Report;

/**
 * The Composer plugin: patches after install and update (every package is
 * on disk then), stubs after the autoloader is dumped, everything written
 * back as published when the plugin itself is uninstalled. It knows no
 * package: what it does comes from the packages' extra.omnibridge.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    private ?Composer $composer = null;
    private ?IOInterface $io = null;

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstall',
            ScriptEvents::POST_UPDATE_CMD => 'onPostInstall',
            ScriptEvents::POST_AUTOLOAD_DUMP => 'onPostAutoloadDump',
        ];
    }

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        $restored = (new Engine($composer, $io))->restoreAll();
        foreach ($restored as $file) {
            $io->write(sprintf('<info>omnibridge/composer</info>: %s written back as published', $file));
        }
    }

    public function getCapabilities(): array
    {
        return [\Composer\Plugin\Capability\CommandProvider::class => CommandProvider::class];
    }

    public function onPostInstall(): void
    {
        if (null === $this->composer || null === $this->io) {
            return;
        }

        $engine = new Engine($this->composer, $this->io);
        $report = $engine->patch(true);
        self::print($report, $this->io);

        // The autoloader was dumped before the patches: a patched class (no
        // longer final, say) may change what a stub provider generates.
        if ([] !== $report->patched || [] !== $report->restored) {
            $this->stubs($engine, true);
        }

        if ([] !== $report->failures()) {
            throw new \RuntimeException($report->failureMessage());
        }
    }

    public function onPostAutoloadDump(): void
    {
        if (null === $this->composer || null === $this->io) {
            return;
        }

        $this->stubs(new Engine($this->composer, $this->io), false);
    }

    private function stubs(Engine $engine, bool $quietWhenUnchanged): void
    {
        $report = $engine->stubs(true);
        if ([] !== $report->counts && (!$quietWhenUnchanged || $report->written || $report->removed)) {
            $this->io->write(sprintf('<info>omnibridge/composer</info>: stubs %s (vendor/%s/stubs)', $report->summary(), Engine::STATE_DIR));
        }
        foreach ($report->problems as $problem) {
            $this->io->writeError('<warning>omnibridge/composer: '.$problem.'</warning>');
        }
    }

    /** The lines Composer prints after an install or an update. */
    public static function print(Report $report, IOInterface $io): void
    {
        if ([] === $report->outcomes && [] === $report->restored) {
            return;
        }

        $io->write('<info>omnibridge/composer</info>: patches');
        foreach ($report->outcomes as $outcome) {
            $line = '  '.$outcome->line();
            $io->write(match (true) {
                $outcome->failed() => '<error>'.$line.'</error>',
                Outcome::APPLIED === $outcome->status => $line,
                default => '<comment>'.$line.'</comment>',
            });
        }
        foreach ($report->replaced as $file) {
            $io->write(sprintf('  %s was replaced by Composer: patched again', $file));
        }
        foreach ($report->restored as $file) {
            $io->write(sprintf('  %s written back as published', $file));
        }
        foreach ($report->problems as $problem) {
            $io->writeError('<warning>  '.$problem.'</warning>');
        }
    }
}
