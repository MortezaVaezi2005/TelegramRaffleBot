<?php declare(strict_types=1);

namespace Amp\Mysql;

use Amp\Sql\SqlException;

/** @see 14.6.4.1.1.1 Column Type */
enum MysqlDataType: int
{
    case Decimal = 0x00;
    case Tiny = 0x01;
    case Short = 0x02;
    case Long = 0x03;
    case Float = 0x04;
    case Double = 0x05;
    case Null = 0x06;
    case Timestamp = 0x07;
    case LongLong = 0x08;
    case Int24 = 0x09;
    case Date = 0x0a;
    case Time = 0x0b;
    case Datetime = 0x0c;
    case Year = 0x0d;
    case NewDate = 0x0e; // Internal, not used in protocol, see Date
    case Varchar = 0x0f;
    case Bit = 0x10;
    case Timestamp2 = 0x11; // Internal, not used in protocol, see Timestamp
    case Datetime2 = 0x12; // Internal, not used in protocol, see DateTime
    case Time2 = 0x13; // Internal, not used in protocol, see Time
    case Json = 0xf5;
    case NewDecimal = 0xf6;
    case Enum = 0xf7;
    case Set = 0xf8;
    case TinyBlob = 0xf9;
    case MediumBlob = 0xfa;
    case LongBlob = 0xfb;
    case Blob = 0xfc;
    case VarString = 0xfd;
    case String = 0xfe;
    case Geometry = 0xff;

    /**
     * @see 14.7.3 Binary Protocol Value
     *
     * @param int<0, max> $offset
     *
     * @throws SqlException
     */
    public function decodeBinary(string $bytes, int &$offset = 0, int $flags = 0): int|float|string|null
    {
        $unsigned = $flags & 0x20;

        switch ($this) {
            case self::String:
            case self::Varchar:
            case self::VarString:
            case self::Enum:
            case self::Set:
            case self::LongBlob:
            case self::MediumBlob:
            case self::Blob:
            case self::TinyBlob:
            case self::Geometry:
            case self::Bit:
            case self::Decimal:
            case self::NewDecimal:
            case self::Json:
                return self::decodeString($bytes, $offset);

            case self::LongLong:
                return $unsigned
                    ? self::decodeUnsigned64($bytes, $offset)
                    : self::decodeInt64($bytes, $offset);

            case self::Long:
                return $unsigned
                    ? self::decodeUnsigned32($bytes, $offset)
                    : self::decodeInt32($bytes, $offset);

            case self::Int24:
                return $unsigned
                    ? self::decodeUnsigned24($bytes, $offset)
                    : self::decodeInt24($bytes, $offset);

            case self::Short:
            case self::Year:
                return $unsigned
                    ? self::decodeUnsigned16($bytes, $offset)
                    : self::decodeInt16($bytes, $offset);

            case self::Tiny:
                return $unsigned
                    ? self::decodeUnsigned8($bytes, $offset)
                    : self::decodeInt8($bytes, $offset);

            case self::Double:
                $offset += 8;
                return \unpack("e", $bytes, $offset - 8)[1];

            case self::Float:
                $offset += 4;
                return \unpack("g", $bytes, $offset - 4)[1];

            case self::Date:
            case self::Datetime:
            case self::Timestamp:
                return $this->decodeDateTime($bytes, $offset);

            case self::Time:
                return $this->decodeTime($bytes, $offset);

            case self::Null:
                return null;

            default:
                throw new SqlException("Invalid type for Binary Protocol: 0x" . \dechex($this->value));
        }
    }

    /**
     * @param int<0, max> $offset
     *
     * @throws SqlException
     */
    public function decodeText(string $bytes, int &$offset = 0, int $flags = 0): int|float|string
    {
        $length = self::decodeUnsigned($bytes, $offset);
        $offset += $length;
        $data = \substr($bytes, $offset - $length, $length);

        switch ($this) {
            case self::LongLong:
                if ($flags & 0x20) {
                    return $data; // Return UNSIGNED BIGINT as a string.
                }
                // no break

            case self::Long:
                if (\PHP_INT_SIZE < 8) {
                    return $data; // Return BIGINT and UNSIGNED INT as string on 32-bit.
                }
                // no break

            case self::Int24:
            case self::Short:
            case self::Tiny:
            case self::Year:
                return (int) $data;

            case self::Double:
            case self::Float:
                return (float) $data;

            default:
                return $data;
        }
    }

    /**
     * @param int<0, max> $offset
     */
    private function decodeDateTime(string $bytes, int &$offset): string
    {
        $year = $month = $day = $hour = $minute = $second = $microsecond = 0;

        switch ($length = self::decodeUnsigned8($bytes, $offset)) {
            case 11:
                $position = $offset + 7;
                $microsecond = self::decodeUnsigned32($bytes, $position);
                // no break

            case 7:
                $position = $offset + 4;
                $hour = self::decodeUnsigned8($bytes, $position);
                $minute = self::decodeUnsigned8($bytes, $position);
                $second = self::decodeUnsigned8($bytes, $position);
                // no break

            case 4:
                $position = $offset;
                $year = self::decodeUnsigned16($bytes, $position);
                $month = self::decodeUnsigned8($bytes, $position);
                $day = self::decodeUnsigned8($bytes, $position);
                // no break

            case 0:
                break;

            default:
                throw new SqlException("Unexpected string length for datetime in binary protocol: $length");
        }

        $offset += $length;

        $result = \sprintf('%04d-%02d-%02d', $year, $month, $day);
        if ($this === self::Date) {
            return $result;
        }

        $result .= \sprintf(' %02d:%02d:%02d', $hour, $minute, $second);
        if ($microsecond) {
            $result .= \sprintf('.%06d', $microsecond);
        }

        return $result;
    }

    /**
     * @param int<0, max> $offset
     */
    private function decodeTime(string $bytes, int &$offset): string
    {
        $negative = $day = $hour = $minute = $second = $microsecond = 0;

        switch ($length = self::decodeUnsigned8($bytes, $offset)) {
            case 12:
                $position = $offset + 8;
                $microsecond = self::decodeUnsigned32($bytes, $position);
                // no break

            case 8:
                $position = $offset;
                $negative = self::decodeUnsigned8($bytes, $position);
                $day = self::decodeUnsigned32($bytes, $position);
                $hour = self::decodeUnsigned8($bytes, $position);
                $minute = self::decodeUnsigned8($bytes, $position);
                $second = self::decodeUnsigned8($bytes, $position);
                // no break

            case 0:
                break;

            default:
                throw new SqlException("Unexpected string length for time in binary protocol: $length");
        }

        $offset += $length;

        $hour += $day * 24;

        $result = \sprintf('%s%02d:%02d:%02d', ($negative ? "-" : ""), $hour, $minute, $second);
        if ($microsecond) {
            $result .= \sprintf('.%06d', $microsecond);
        }

        return $result;
    }

    /**
     * @param int<0, max> $offset
     */
    public static function decodeNullTerminatedString(string $bytes, int &$offset = 0): string
    {
        $length = \strpos($bytes, "\0", $offset);
        if ($length === false) {
            throw new \ValueError('Null not found in string');
        }

        $length -= $offset;
        $result = \substr($bytes, $offset, $length);
        $offset += $length + 1;
        \assert($offset >= 0);

        return $result;
    }

    /**
     * @param int<0, max> $offset
     *
     * @throws SqlException
     */
    public static function decodeString(string $bytes, int &$offset = 0): string
    {
        $length = self::decodeUnsigned($bytes, $offset);
        $offset += $length;
        return \substr($bytes, $offset - $length, $length);
    }

    /**
     * @param int<0, max> $offset
     *
     * @return int<0, max>
     */
    public static function decodeUnsigned(string $bytes, int &$offset = 0): int
    {
        $int = self::decodeUnsigned8($bytes, $offset);
        if ($int < 0xfb) {
            return $int;
        }

        return match ($int) {
            0xfc => self::decodeUnsigned16($bytes, $offset),
            0xfd => self::decodeUnsigned24($bytes, $offset),
            0xfe => self::decodeUnsigned64($bytes, $offset),
            // If this happens connection is borked...
            default => throw new SqlException("$int is not in ranges [0x00, 0xfa] or [0xfc, 0xfe]"),
        };
    }

    /**
     * @param int<0, max> $offset
     * @param int<0, max> $length
     */
    public static function decodeIntByLength(string $bytes, int $length, int &$offset = 0): int
    {
        $int = 0;
        while ($length) {
            $int = ($int << 8) + \ord($bytes[--$length + $offset]);
        }

        $offset += $length;

        return $int;
    }

    /**
     * @param int<0, max> $offset
     */
    public static function decodeInt8(string $bytes, int &$offset = 0): int
    {
        $int = \ord($bytes[$offset++]);
        if ($int < (1 << 7)) {
            return $int;
        }
        $shift = \PHP_INT_SIZE * 8 - 8;
        return $int << $shift >> $shift;
    }

    /**
     * @param int<0, max> $offset
     *
     * @return int<0, max>
     */
    public static function decodeUnsigned8(string $bytes, int &$offset = 0): int
    {
        $result = \ord($bytes[$offset++]);
        \assert($result >= 0);
        return $result;
    }

    /**
     * @param int<0, max> $offset
     */
    public static function decodeInt16(string $bytes, int &$offset = 0): int
    {
        $int = \unpack("v", $bytes, $offset)[1];
        $offset += 2;
        if ($int < (1 << 15)) {
            return $int;
        }
        $shift = \PHP_INT_SIZE * 8 - 16;
        return $int << $shift >> $shift;
    }

    /**
     * @param int<0, max> $offset
     *
     * @return int<0, max>
     */
    public static function decodeUnsigned16(string $bytes, int &$offset = 0): int
    {
        $offset += 2;
        return \unpack("v", $bytes, $offset - 2)[1];
    }

    /**
     * @param int<0, max> $offset
     */
    public static function decodeInt24(string $bytes, int &$offset = 0): int
    {
        $int = \unpack("V", \substr($bytes, $offset, 3) . "\x00")[1];
        $offset += 3;
        if ($int < (1 << 23)) {
            return $int;
        }
        $shift = \PHP_INT_SIZE * 8 - 24;
        return $int << $shift >> $shift;
    }

    /**
     * @param int<0, max> $offset
     *
     * @return int<0, max>
     */
    public static function decodeUnsigned24(string $bytes, int &$offset = 0): int
    {
        $result = \unpack("V", \substr($bytes, $offset, 3) . "\x00")[1];
        $offset += 3;
        return $result;
    }

    /**
     * @param int<0, max> $offset
     */
    public static function decodeInt32(string $bytes, int &$offset = 0): int
    {
        $offset += 4;

        if (\PHP_INT_SIZE > 4) {
            $int = \unpack("V", $bytes, $offset - 4)[1];
            if ($int < (1 << 31)) {
                return $int;
            }
            return $int << 32 >> 32;
        }

        return \unpack("V", $bytes, $offset - 4)[1];
    }

    /**
     * @param int<0, max> $offset
     *
     * @return int<0, max>
     */
    public static function decodeUnsigned32(string $bytes, int &$offset = 0): int
    {
        $result = \unpack("V", $bytes, $offset)[1];
        $offset += 4;

        if ($result < 0) {
            throw new \RuntimeException('Expecting a non-negative integer');
        }

        return $result;
    }

    /**
     * @param int<0, max> $offset
     *
     * @return int<0, max>|string
     */
    public static function decodeUnsigned32WithGmp(string $bytes, int &$offset = 0): int|string
    {
        $offset += 4;

        if (\PHP_INT_SIZE > 4) {
            return \unpack("V", $bytes, $offset - 4)[1];
        }

        \assert(\extension_loaded("gmp"), "The GMP extension is required for UNSIGNED INT fields on 32-bit systems");
        /** @psalm-suppress UndefinedConstant */
        return \gmp_strval(\gmp_import(\substr($bytes, $offset - 4, 4), 1, \GMP_LSW_FIRST));
    }

    /**
     * @param int<0, max> $offset
     */
    public static function decodeInt64(string $bytes, int &$offset = 0): int
    {
        if (\PHP_INT_SIZE > 4) {
            $offset += 8;
            return \unpack("P", $bytes, $offset - 8)[1];
        }

        throw new \RuntimeException('64-bit integers are not supported by 32-bit builds of PHP');
    }

    public static function decodeInt64WithGmp(string $bytes, int &$offset = 0): int|string
    {
        $offset += 8;

        if (\PHP_INT_SIZE > 4) {
            return \unpack("P", $bytes, $offset - 8)[1];
        }

        \assert(\extension_loaded("gmp"), "The GMP extension is required for BIGINT fields on 32-bit systems");
        /** @psalm-suppress UndefinedConstant */
        return \gmp_strval(\gmp_import(\substr($bytes, $offset - 8, 8), 1, \GMP_LSW_FIRST));
    }

    /**
     * @param int<0, max> $offset
     *
     * @return int<0, max>
     */
    public static function decodeUnsigned64(string $bytes, int &$offset = 0): int
    {
        if (\PHP_INT_SIZE <= 4) {
            throw new \RuntimeException('64-bit integers are not supported by 32-bit builds of PHP');
        }

        $result = \unpack("P", $bytes, $offset)[1];
        $offset += 8;

        if ($result < 0) {
            throw new \RuntimeException('Expecting a non-negative integer');
        }

        return $result;
    }

    /**
     * @param int<0, max> $offset
     */
    public static function decodeUnsigned64WithGmp(string $bytes, int &$offset = 0): string
    {
        $offset += 8;

        \assert(\extension_loaded("gmp"), "The GMP extension is required for UNSIGNED BIGINT fields");
        /** @psalm-suppress UndefinedConstant */
        return \gmp_strval(\gmp_import(\substr($bytes, $offset - 8, 8), 1, \GMP_LSW_FIRST));
    }

    public static function encodeInt(int $int): string
    {
        if ($int < 0xfb) {
            return self::encodeInt8($int);
        }

        if ($int < (1 << 16)) {
            return "\xfc" . self::encodeInt16($int);
        }

        if ($int < (1 << 24)) {
            return "\xfd" . self::encodeInt24($int);
        }

        return "\xfe" . self::encodeInt64($int);
    }

    public static function encodeInt8(int $int): string
    {
        return \chr($int);
    }

    public static function encodeInt16(int $int): string
    {
        return \pack("v", $int);
    }

    public static function encodeInt24(int $int): string
    {
        return \substr(\pack("V", $int), 0, 3);
    }

    public static function encodeInt32(int $int): string
    {
        return \pack("V", $int);
    }

    public static function encodeInt64(int $int): string
    {
        return \pack("VV", $int & 0xffffffff, $int >> 32);
    }
}
