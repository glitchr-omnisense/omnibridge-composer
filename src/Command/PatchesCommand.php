<?php

namespace Omnibridge\Composer\Command;

use Composer\Command\BaseCommand;
use Omnibridge\Composer\Engine;
use Omnibridge\Composer\Patch\Diff;
use Omnibridge\Composer\Patch\Outcome;
use Omnibridge\Composer\Plugin;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * composer omnibridge:patches            the declared patches: applied, skipped (and why), failed
 * composer omnibridge:patches --diff     what the applied ones changed, as a unified diff
 * composer omnibridge:patches --check    for CI: fails when a patch no longer finds its target or vendor/ differs from the record
 * composer omnibridge:patches --apply    applies them now (what install and update do)
 */
final class PatchesCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('omnibridge:patches')
            ->setDescription('Lists the patches the installed packages declare (extra.omnibridge.patches), shows or checks what they changed in vendor/.')
            ->addOption('diff', null, InputOption::VALUE_NONE, 'Show what the applied patches changed, as a unified diff')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Fail when a patch no longer finds its target or a vendor file differs from the record (CI)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the patches now, as install and update do');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer();
        $io = $this->getIO();
        $engine = new Engine($composer, $io);

        if ($input->getOption('apply')) {
            $report = $engine->patch(true);
            Plugin::print($report, $io);

            return [] === $report->failures() ? 0 : 1;
        }

        if ($input->getOption('diff')) {
            foreach ($engine->reconciler()->recordedChanges() as $key => [$before, $after]) {
                $output->write(Diff::unified($before, $after, $key), false, OutputInterface::OUTPUT_RAW);
            }

            return 0;
        }

        $report = $engine->patch(false);

        if ($input->getOption('check')) {
            foreach ($report->failures() as $outcome) {
                $io->writeError('<error>'.$outcome->line().'</error>');
            }
            foreach ($report->problems as $problem) {
                $io->writeError('<error>'.$problem.'</error>');
            }
            if (!$report->isClean()) {
                $io->writeError(sprintf('<error>omnibridge:patches --check: %d problem(s)</error>', count($report->failures()) + count($report->problems)));

                return 1;
            }
            $io->write(sprintf('omnibridge:patches --check: %d patch(es) applied as recorded, vendor/ as declared', count($report->applied())));

            return 0;
        }

        if ([] === $report->outcomes) {
            $io->write('No package declares a patch (extra.omnibridge.patches).');

            return 0;
        }

        $groups = [
            'applied' => static fn (Outcome $o) => Outcome::APPLIED === $o->status,
            'skipped' => static fn (Outcome $o) => !$o->failed() && Outcome::APPLIED !== $o->status,
            'failed' => static fn (Outcome $o) => $o->failed(),
        ];
        $io->write(sprintf('%d declared', count($report->outcomes)));
        foreach ($groups as $title => $filter) {
            $outcomes = array_filter($report->outcomes, $filter);
            if (!$outcomes) {
                continue;
            }
            $io->write(sprintf('<info>%s</info> (%d)', $title, count($outcomes)));
            foreach ($outcomes as $outcome) {
                $patch = $outcome->patch;
                $io->write(sprintf('  %s', $outcome->key));
                if (null !== $patch) {
                    $io->write(sprintf('    %s %s%s', $patch->package, $patch->file, null !== $patch->versions ? ' (versions '.$patch->versions.')' : ''));
                    $io->write(sprintf('    why: %s', $patch->why));
                    if (null !== $patch->upstream) {
                        $io->write(sprintf('    upstream: %s', $patch->upstream));
                    }
                }
                if ('' !== $outcome->reason) {
                    $io->write(sprintf('    %s', Outcome::APPLIED === $outcome->status ? $outcome->reason : $outcome->status.': '.$outcome->reason));
                }
            }
        }
        foreach ($report->problems as $problem) {
            $io->writeError('<warning>'.$problem.'</warning>');
        }

        return [] === $report->failures() ? 0 : 1;
    }
}
