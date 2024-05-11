<?php declare(strict_types=1);

namespace Amp\Log;

use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;
use Psr\Log\LogLevel;

final class ConsoleFormatter extends LineFormatter
{
    public const DEFAULT_FORMAT = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\r\n";

    private readonly bool $ansify;

    public function __construct(
        ?string $format = null,
        ?string $dateFormat = null,
        bool $allowInlineLineBreaks = false,
        bool $ignoreEmptyContextAndExtra = false,
    ) {
        parent::__construct(
            $format ?? self::DEFAULT_FORMAT,
            $dateFormat,
            $allowInlineLineBreaks,
            $ignoreEmptyContextAndExtra
        );

        $this->includeStacktraces = false;
        $this->ansify = $this->determineAnsiColorOption();
    }

    /**
     * Applies ansi colors to log record for Monolog v1.x and v2.x.
     *
     * @param array{level_name: string, channel: string}|LogRecord $record Array for Monolog v1.x or 2.x
     *      and LogRecord for v3.x.
     */
    public function format(array|LogRecord $record): string
    {
        // For Monolog v1.x and v2.x
        if (\is_array($record) && $this->ansify) {
            $record['level_name'] = $this->ansifyLevel(\strtolower($record['level_name']));
            $record['channel'] = "\033[1m{$record['channel']}\033[0m";
        }

        /** @psalm-suppress PossiblyInvalidArgument */
        return parent::format($record);
    }

    /**
     * Applies ansi colors to log record for Monolog v3.x.
     */
    protected function normalizeRecord(LogRecord $record): array
    {
        $fields = parent::normalizeRecord($record);

        if ($this->ansify) {
            $fields['level_name'] = $this->ansifyLevel($record->level->toPsrLogLevel());
            $fields['channel'] = "\033[1m{$record->channel}\033[0m";
        }

        return $fields;
    }

    private function determineAnsiColorOption(): bool
    {
        $value = \getenv("AMP_LOG_COLOR");
        if ($value === false || $value === '') {
            $value = "auto";
        }

        return match (\strtolower($value)) {
            "1", "true", "on" => true,
            "0", "false", "off" => false,
            default => hasColorSupport(),
        };
    }

    private function ansifyLevel(string $level): string
    {
        return "\033[" . match ($level) {
            LogLevel::EMERGENCY => "43",
            LogLevel::ALERT => "45",
            LogLevel::CRITICAL => "41",
            LogLevel::ERROR => "0;31",
            LogLevel::WARNING => "0;33",
            LogLevel::NOTICE => "0;32",
            LogLevel::INFO => "0;34",
            LogLevel::DEBUG => "0;36",
            default => "40",
        } . "m" . $level . "\033[0m";
    }
}
