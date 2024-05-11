<?php declare(strict_types=1);

namespace Amp\File\Test;

use const Amp\Process\IS_WINDOWS;

final class Fixture
{
    private static string $fixtureId;

    public static function path(): string
    {
        if (empty(self::$fixtureId)) {
            self::$fixtureId = \uniqid('amphp-test-', true);
        }

        return \sys_get_temp_dir() . "/amphp_file_fixture/" . \str_replace("\\", ".", __CLASS__) . self::$fixtureId;
    }

    public static function init(): void
    {
        $fixtureDir = self::path();
        self::clear();
        if (!\mkdir($fixtureDir, $mode = 0777, $recursive = true)) {
            throw new \RuntimeException(
                "Failed creating temporary test fixture directory: {$fixtureDir}"
            );
        }
        if (!\mkdir($fixtureDir . "/dir", $mode = 0777, $recursive = true)) {
            throw new \RuntimeException(
                "Failed creating temporary test fixture directory"
            );
        }
        if (!\file_put_contents($fixtureDir . "/file", "small")) {
            throw new \RuntimeException(
                "Failed creating temporary test fixture file"
            );
        }
        if (!\symlink($fixtureDir . "/dir", $fixtureDir . "/dirlink")) {
            throw new \RuntimeException(
                "Failed creating temporary test fixture symlink to directory"
            );
        }
        if (!\symlink($fixtureDir . "/file", $fixtureDir . "/filelink")) {
            throw new \RuntimeException(
                "Failed creating temporary test fixture symlink to file"
            );
        }
        if (!IS_WINDOWS) {
            if (!\symlink($fixtureDir . "/linkloop", $fixtureDir . "/linkloop")) {
                throw new \RuntimeException(
                    "Failed creating temporary test fixture symlink loop"
                );
            }
        }
        if (\extension_loaded('posix')) {
            if (!\posix_mkfifo($fixtureDir . "/fifo", 0777)) {
                throw new \RuntimeException(
                    "Failed creating temporary test fixture fifo"
                );
            }
            if (!\symlink($fixtureDir . "/fifo", $fixtureDir . "/fifolink")) {
                throw new \RuntimeException(
                    "Failed creating temporary test fixture symlink to file"
                );
            }
        }
    }

    public static function clear(): void
    {
        \clearstatcache(true);

        $fixtureDir = self::path();
        if (!\file_exists($fixtureDir)) {
            return;
        }

        if (\stripos(\PHP_OS, "win") === 0) {
            \system('rd /Q /S "' . $fixtureDir . '"');
        } else {
            \system('/bin/rm -rf ' . \escapeshellarg($fixtureDir));
        }

        \clearstatcache(true);
    }
}
