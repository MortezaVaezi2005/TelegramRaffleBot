<?php declare(strict_types=1);

namespace Amp\Log;

function hasColorSupport(): bool
{
    static $supported;

    if ($supported !== null) {
        return $supported;
    }

    \set_error_handler(static fn () => true);

    try {
        // @see https://github.com/symfony/symfony/blob/v4.0.6/src/Symfony/Component/Console/Output/StreamOutput.php#L91
        // @license https://github.com/symfony/symfony/blob/v4.0.6/LICENSE
        if (\PHP_OS_FAMILY === 'Windows') {
            /** @psalm-suppress UndefinedConstant */
            $windowsVersion = \sprintf(
                '%d.%d.%d',
                \PHP_WINDOWS_VERSION_MAJOR,
                \PHP_WINDOWS_VERSION_MINOR,
                \PHP_WINDOWS_VERSION_BUILD,
            );

            return $supported = (\function_exists('sapi_windows_vt100_support') && sapi_windows_vt100_support(\STDOUT))
                || $windowsVersion === '10.0.10586' // equals is correct here, newer versions use the above function
                || false !== \getenv('ANSICON')
                || 'ON' === \getenv('ConEmuANSI')
                || 'xterm' === \getenv('TERM');
        }

        if (\function_exists('posix_isatty')) {
            return $supported = \posix_isatty(\STDOUT);
        }
    } finally {
        \restore_error_handler();
    }

    return $supported = false;
}
