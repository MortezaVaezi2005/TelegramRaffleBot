<?php declare(strict_types=1);

namespace Amp\Redis\Protocol;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Parser\Parser;
use Generator as ParserGeneratorType;

/**
 * @psalm-type ParserGeneratorType = \Generator<int, int|string, string, RedisResponse>
 */
final class RespParser
{
    use ForbidCloning;
    use ForbidSerialization;

    private const CRLF = "\r\n";

    private const TYPE_SIMPLE_STRING = '+';
    private const TYPE_ERROR = '-';
    private const TYPE_ARRAY = '*';
    private const TYPE_BULK_STRING = '$';
    private const TYPE_INTEGER = ':';

    private readonly Parser $parser;

    /**
     * @psalm-param \Closure(RedisResponse):void $push
     */
    public function __construct(\Closure $push)
    {
        $this->parser = new Parser(self::parser($push));
    }

    public function push(string $data): void
    {
        $this->parser->push($data);
    }

    public function cancel(): void
    {
        $this->parser->cancel();
    }

    /**
     * @param \Closure(RedisResponse):void $push
     *
     * @return \Generator<int, int|string, string, void>
     */
    private static function parser(\Closure $push): \Generator
    {
        while (true) {
            $push(yield from self::parseValue(yield 1, yield self::CRLF));
        }
    }

    /**
     * @psalm-return ParserGeneratorType
     */
    private static function parseValue(string $type, string $payload): \Generator
    {
        return match ($type) {
            self::TYPE_SIMPLE_STRING => new RedisValue($payload),
            self::TYPE_INTEGER => new RedisValue((int) $payload),
            self::TYPE_BULK_STRING => yield from self::parseString((int) $payload),
            self::TYPE_ARRAY => yield from self::parseArray((int) $payload),
            self::TYPE_ERROR => new RedisError($payload),
            default => throw new ProtocolException('Unknown resp data type: ' . $type),
        };
    }

    /**
     * @psalm-return ParserGeneratorType
     */
    private static function parseString(int $length): \Generator
    {
        if ($length < -1) {
            throw new ProtocolException('Invalid string length: ' . $length);
        }

        if ($length === -1) {
            return new RedisValue(null);
        }

        $payload = match ($length) {
            0 => '',
            default => yield $length,
        };

        yield 2; // Remove trailing CRLF

        return new RedisValue($payload);
    }

    /**
     * @psalm-return ParserGeneratorType
     */
    private static function parseArray(int $count): \Generator
    {
        if ($count < -1) {
            throw new ProtocolException('Invalid array length: ' . $count);
        }

        if ($count === -1) {
            return new RedisValue(null);
        }

        $payload = [];
        for ($i = 0; $i < $count; $i++) {
            $payload[] = (yield from self::parseValue(yield 1, yield self::CRLF))->unwrap();
        }

        return new RedisValue($payload);
    }
}
