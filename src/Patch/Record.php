<?php

namespace Omnibridge\Composer\Patch;

/**
 * What the engine applied, in vendor/composer/omnibridge/: patches.json - for
 * each patched file its package and version, the patches applied in order,
 * the SHA-256 of the file as published (before) and as patched (after) - and
 * originals/<package>/<file>, the published file, which a restore writes
 * back. No date, no random id: two installs of the same lock write the same
 * record, which is what `omnibridge:patches --check` compares in CI.
 */
final class Record
{
    public const FILE = 'patches.json';

    /** @var array<string, array{package: string, version: string, file: string, path: string, before: string, after: string, patches: string[]}> */
    private array $files = [];

    public function __construct(private readonly string $directory)
    {
        $file = $directory.'/'.self::FILE;
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            $this->files = is_array($data['files'] ?? null) ? $data['files'] : [];
        }
    }

    public static function hash(string $content): string
    {
        return 'sha256:'.hash('sha256', $content);
    }

    /** @return array<string, array{package: string, version: string, file: string, path: string, before: string, after: string, patches: string[]}> */
    public function files(): array
    {
        return $this->files;
    }

    public function get(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /** @param string[] $patches */
    public function set(string $key, string $package, string $version, string $file, string $path, string $original, string $patched, array $patches): void
    {
        $this->files[$key] = [
            'package' => $package,
            'version' => $version,
            'file' => $file,
            'path' => $path,
            'before' => self::hash($original),
            'after' => self::hash($patched),
            'patches' => $patches,
        ];

        $originalFile = $this->originalPath($key);
        if (!is_file($originalFile) || file_get_contents($originalFile) !== $original) {
            if (!is_dir(dirname($originalFile))) {
                mkdir(dirname($originalFile), 0777, true);
            }
            file_put_contents($originalFile, $original);
        }
    }

    public function remove(string $key): void
    {
        unset($this->files[$key]);
        $originalFile = $this->originalPath($key);
        if (is_file($originalFile)) {
            unlink($originalFile);
        }
        $this->prune(dirname($originalFile));
    }

    /** The published content of a recorded file, null when missing or not the one recorded. */
    public function original(string $key): ?string
    {
        $file = $this->originalPath($key);
        if (!isset($this->files[$key]) || !is_file($file)) {
            return null;
        }

        $content = (string) file_get_contents($file);

        return self::hash($content) === $this->files[$key]['before'] ? $content : null;
    }

    public function save(): void
    {
        $file = $this->directory.'/'.self::FILE;
        if ([] === $this->files) {
            if (is_file($file)) {
                unlink($file);
            }
            $this->prune($this->directory.'/originals');
            $this->prune($this->directory);

            return;
        }

        ksort($this->files);
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0777, true);
        }

        $json = json_encode([
            '_readme' => 'Written by omnibridge/composer: the files it patched, as published (before) and as patched (after). `composer omnibridge:patches --check` compares vendor/ with it.',
            'files' => $this->files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        if (!is_file($file) || file_get_contents($file) !== $json) {
            file_put_contents($file, $json);
        }
    }

    private function originalPath(string $key): string
    {
        return $this->directory.'/originals/'.$key;
    }

    /** Removes $directory and its parents up to the record's own while they are empty. */
    private function prune(string $directory): void
    {
        while (is_dir($directory) && str_starts_with($directory, $this->directory) && [] === array_diff((array) scandir($directory), ['.', '..'])) {
            rmdir($directory);
            if ($directory === $this->directory) {
                break;
            }
            $directory = dirname($directory);
        }
    }
}
