<?php declare(strict_types=1);

namespace Amp\Mysql\Internal;

use Amp\Mysql\MysqlDataType;

/** @internal */
final class MysqlEncodedValue
{
    public static function fromValue(mixed $param): self
    {
        switch (\get_debug_type($param)) {
            case "string":
                return new self(MysqlDataType::LongBlob, MysqlDataType::encodeInt(\strlen($param)) . $param);

            case "int":
                if ($param >= -(1 << 7) && $param < (1 << 7)) {
                    return new self(MysqlDataType::Tiny, MysqlDataType::encodeInt8($param));
                }

                if ($param >= -(1 << 15) && $param < (1 << 15)) {
                    return new self(MysqlDataType::Short, MysqlDataType::encodeInt16($param));
                }

                if ($param >= -(1 << 31) && $param < (1 << 31)) {
                    return new self(MysqlDataType::Long, MysqlDataType::encodeInt32($param));
                }

                return new self(MysqlDataType::LongLong, MysqlDataType::encodeInt64($param));

            case "float":
                return new self(MysqlDataType::Double, \pack("e", $param));

            case "bool":
                $encoded = $param ? "\x01" : "\0";
                return new self(MysqlDataType::Tiny, $encoded);

            case "null":
                return new self(MysqlDataType::Null, "");

            default:
                if ($param instanceof \BackedEnum) {
                    return self::fromValue($param->value);
                }

                if ($param instanceof \Stringable) {
                    return self::fromValue((string) $param);
                }

                throw new \TypeError("Unexpected type for query parameter: " . \get_debug_type($param));
        }
    }

    public static function fromJson(string $json): self
    {
        return new self(MysqlDataType::Json, MysqlDataType::encodeInt(\strlen($json)) . $json);
    }

    private function __construct(
        private readonly MysqlDataType $type,
        private readonly string $bytes,
    ) {
    }

    public function getType(): MysqlDataType
    {
        return $this->type;
    }

    public function getBytes(): string
    {
        return $this->bytes;
    }
}
