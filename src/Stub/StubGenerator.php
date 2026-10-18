<?php

namespace Omnibridge\Composer\Stub;

/**
 * Runs the declared stub providers into one directory the engine owns
 * (vendor/composer/omnibridge/stubs): each provider's files under its name,
 * a file written only when its content changes, what no provider returns any
 * more removed.
 */
final class StubGenerator
{
    public function __construct(private readonly string $outputDir)
    {
    }

    /**
     * @param array<array{StubInterface, StubContext}> $providers
     * @param bool $write  false: compare only (--check)
     */
    public function run(array $providers, bool $write = true): StubReport
    {
        $report = new StubReport();
        $wanted = [];

        foreach ($providers as [$provider, $context]) {
            $name = $provider->getName();
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
                $report->problems[] = sprintf('%s: "%s" is not a directory name', $provider::class, $name);
                continue;
            }
            if (isset($report->counts[$name])) {
                $report->problems[] = sprintf('%s: another provider already writes the stubs "%s"', $provider::class, $name);
                continue;
            }

            $report->counts[$name] = 0;
            foreach ($provider->generate($context) as $relative => $content) {
                $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
                if ('' === $relative || in_array('..', explode('/', $relative), true)) {
                    $report->problems[] = sprintf('%s: "%s" is not a path inside its directory', $provider::class, $relative);
                    continue;
                }
                $wanted[$name.'/'.$relative] = $content;
                ++$report->counts[$name];
            }
        }

        ksort($wanted, SORT_STRING);
        foreach ($wanted as $relative => $content) {
            $file = $this->outputDir.'/'.$relative;
            if (is_file($file) && file_get_contents($file) === $content) {
                continue;
            }
            if ($write) {
                if (!is_dir(dirname($file))) {
                    mkdir(dirname($file), 0777, true);
                }
                file_put_contents($file, $content);
                $report->written[] = $relative;
            } else {
                $report->problems[] = sprintf('%s is %s', $relative, is_file($file) ? 'not what its provider generates' : 'missing');
            }
        }

        foreach ($this->existing() as $relative) {
            if (isset($wanted[$relative])) {
                continue;
            }
            if ($write) {
                unlink($this->outputDir.'/'.$relative);
                $report->removed[] = $relative;
            } else {
                $report->problems[] = sprintf('%s is no provider\'s any more', $relative);
            }
        }

        if ($write) {
            $this->prune($this->outputDir);
        }

        return $report;
    }

    /** @return string[] the files under the output directory, relative to it */
    private function existing(): array
    {
        if (!is_dir($this->outputDir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->outputDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($this->outputDir) + 1);
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /** Removes the empty directories under (and including) $directory. */
    private function prune(string $directory): bool
    {
        if (!is_dir($directory)) {
            return true;
        }

        $empty = true;
        foreach (array_diff((array) scandir($directory), ['.', '..']) as $entry) {
            $path = $directory.'/'.$entry;
            if (!is_dir($path) || !$this->prune($path)) {
                $empty = false;
            }
        }

        if ($empty) {
            rmdir($directory);
        }

        return $empty;
    }
}
