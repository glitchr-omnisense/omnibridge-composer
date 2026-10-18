<?php

namespace Omnibridge\Composer\Command;

use Composer\Command\BaseCommand;
use Omnibridge\Composer\Engine;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * composer omnibridge:stubs           regenerates the stubs (what dump-autoload does)
 * composer omnibridge:stubs --check   for CI: fails when the stubs on disk are not what the providers generate
 */
final class StubsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('omnibridge:stubs')
            ->setDescription('Generates the stubs the installed packages declare (extra.omnibridge.stubs) into vendor/composer/omnibridge/stubs, or checks them.')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Fail when a stub on disk is not what its provider generates (CI)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $engine = new Engine($this->requireComposer(), $this->getIO());
        $check = (bool) $input->getOption('check');
        $report = $engine->stubs(!$check);
        $io = $this->getIO();

        foreach ($report->problems as $problem) {
            $io->writeError(($check ? '<error>' : '<warning>').$problem.($check ? '</error>' : '</warning>'));
        }
        $io->write(sprintf('omnibridge:stubs%s: %s', $check ? ' --check' : '', [] === $report->counts ? 'no provider declared' : $report->summary()));
        if (!$check && ($report->written || $report->removed)) {
            $io->write(sprintf('  %d written, %d removed', count($report->written), count($report->removed)));
        }

        return [] === $report->problems ? 0 : 1;
    }
}
