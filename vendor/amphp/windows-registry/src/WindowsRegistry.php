<?php declare(strict_types=1);

namespace Amp\WindowsRegistry;

use Amp\ByteStream;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Process\Process;

final class WindowsRegistry
{
    use ForbidCloning;
    use ForbidSerialization;

    private function __construct()
    {
        // forbid instances
    }

    public static function read(string $key): string
    {
        $key = \str_replace('/', "\\", $key);
        $parts = \explode("\\", $key);

        $value = \array_pop($parts);
        $key = \implode("\\", $parts);

        $lines = self::query($key);

        $lines = \array_filter($lines, static function ($line) {
            return '' !== $line && $line[0] === ' ';
        });

        $values = \array_map(static function ($line) {
            return \preg_split("(\\s+)", \ltrim($line), 3);
        }, $lines);

        $foundValue = null;

        foreach ($values as $v) {
            if ($v[0] === $value) {
                if (\count($v) >= 3) {
                    return $v[2];
                }

                $foundValue = $v;
            }
        }

        if ($foundValue) {
            throw new KeyNotFoundException("Windows registry key '{$key}\\{$value}' was found, but could not be read correctly, got " . \var_export(
                $foundValue,
                true
            ));
        }

        throw new KeyNotFoundException("Windows registry key '{$key}\\{$value}' not found.");
    }

    public static function listKeys(string $key): array
    {
        $lines = self::query($key);

        return \array_filter($lines, static function ($line) {
            return '' !== $line && $line[0] !== ' ';
        });
    }

    private static function query(string $key): array
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            throw new \Error('Not running on Windows: ' . \PHP_OS_FAMILY);
        }

        $process = Process::start(['reg', 'query', \str_replace('/', "\\", $key)]);
        $stdout = ByteStream\buffer($process->getStdout());
        $exitCode = $process->join();

        if ($exitCode !== 0) {
            throw new KeyNotFoundException("Windows registry key '{$key}' not found.");
        }

        return \explode("\n", \str_replace("\r", '', $stdout));
    }
}
