<?php declare(strict_types=1);

namespace Amp\Log;

use Amp\ByteStream\WritableStream;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Log\LogLevel;

final class StreamHandler extends AbstractProcessingHandler
{
    private WritableStream $sink;

    /**
     * @param WritableStream $sink Stream to write the logs to
     * @param Level|value-of<Level>|LogLevel::* $level The minimum logging level at which this handler will be triggered
     * @param bool $bubble Whether the messages that are handled can bubble up the stack or not
     */
    public function __construct(WritableStream $sink, int|string|Level $level = LogLevel::DEBUG, bool $bubble = true)
    {
        /** @psalm-suppress PossiblyInvalidArgument */
        parent::__construct($level, $bubble);

        $this->sink = $sink;
    }

    /**
     * @param array{formatted: string}|LogRecord $record Array for Monolog v1.x or 2.x and LogRecord for v3.x.
     */
    protected function write(array|LogRecord $record): void
    {
        $formatted = \is_array($record) ? $record['formatted'] : $record->formatted;

        if (!\is_string($formatted)) {
            throw new \ValueError(\sprintf(
                '%s only supports writing string formatted log records, got "%s"',
                self::class,
                \get_debug_type($formatted),
            ));
        }

        $this->sink->write($formatted);
    }
}
