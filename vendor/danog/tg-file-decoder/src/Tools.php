<?php declare(strict_types=1);

namespace danog\Decoder;

\define('BIG_ENDIAN', \pack('L', 1) === \pack('N', 1));

/** @internal */
final class Tools
{
    public const WEB_LOCATION_FLAG =  1 << 24;
    public const FILE_REFERENCE_FLAG = 1 << 25;

    /**
     * Unpack long properly, returns an actual number in any case.
     *
     * @internal
     *
     * @param string $field Field to unpack
     */
    public static function unpackLong(string $field): int
    {
        /** @var int */
        return \unpack('q', BIG_ENDIAN ? \strrev($field) : $field)[1];
    }
    /**
     * Unpack integer.
     * @internal
     *
     * @param string $field Field to unpack
     */
    public static function unpackInt(string $field): int
    {
        /** @var int */
        return \unpack('l', $field)[1];
    }
    /**
     * Pack string long.
     *
     * @internal
     *
     */
    public static function packLong(int $field): string
    {
        $res = \pack('q', $field);
        return BIG_ENDIAN ? \strrev($res) : $res;
    }
    /**
     * Base64URL decode.
     *
     * @param string $data Data to decode
     *
     * @internal
     *
     */
    public static function base64urlDecode(string $data): string
    {
        return \base64_decode(\str_pad(\strtr($data, '-_', '+/'), \strlen($data) % 4, '=', STR_PAD_RIGHT));
    }

    /**
     * Base64URL encode.
     *
     * @param string $data Data to encode
     *
     * @internal
     *
     */
    public static function base64urlEncode(string $data): string
    {
        return \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Null-byte RLE decode.
     *
     * @param string $string Data to decode
     *
     * @internal
     *
     */
    public static function rleDecode(string $string): string
    {
        $new = '';
        $last = '';
        $null = "\0";
        foreach (\str_split($string) as $cur) {
            if ($last === $null) {
                $new .= \str_repeat($last, \ord($cur));
                $last = '';
            } else {
                $new .= $last;
                $last = $cur;
            }
        }
        $string = $new.$last;

        return $string;
    }

    /**
     * Null-byte RLE encode.
     *
     * @param string $string Data to encode
     *
     * @internal
     *
     */
    public static function rleEncode(string $string): string
    {
        $new = '';
        $count = 0;
        $null = "\0";
        foreach (\str_split($string) as $cur) {
            if ($cur === $null) {
                ++$count;
            } else {
                if ($count > 0) {
                    $new .= $null.\chr($count);
                    $count = 0;
                }
                $new .= $cur;
            }
        }
        if ($count > 0) {
            $new .= $null.\chr($count);
        }

        return $new;
    }

    /**
     * Positive modulo
     * Works just like the % (modulus) operator, only returns always a postive number.
     *
     * @param int $a A
     * @param int $b B
     *
     * @internal
     *
     * @return int Modulo
     */
    public static function posmod(int $a, int $b): int
    {
        $resto = $a % $b;

        return $resto < 0 ? $resto + \abs($b) : $resto;
    }

    /**
     * Read TL string.
     *
     * @param resource $stream Byte stream
     *
     * @internal
     *
     */
    public static function readTLString(mixed $stream): string
    {
        $l = \ord(\stream_get_contents($stream, 1));
        if ($l > 254) {
            throw new \InvalidArgumentException("Length too big!");
        }
        if ($l === 254) {
            /** @var int */
            $long_len = \unpack('V', \stream_get_contents($stream, 3).\chr(0))[1];
            $x = \stream_get_contents($stream, $long_len);
            $resto = self::posmod(-$long_len, 4);
            if ($resto > 0) {
                \fseek($stream, $resto, SEEK_CUR);
            }
        } else {
            $x = $l ? \stream_get_contents($stream, $l) : '';
            $resto = self::posmod(-($l + 1), 4);
            if ($resto > 0) {
                \fseek($stream, $resto, SEEK_CUR);
            }
        }
        \assert($x !== false);
        return $x;
    }

    /**
     * Pack TL string.
     *
     * @param string $string String
     *
     * @internal
     */
    public static function packTLString(string $string): string
    {
        $l = \strlen($string);
        $concat = '';
        if ($l <= 253) {
            $concat .= \chr($l);
            $concat .= $string;
            $concat .= \pack('@'.self::posmod(-$l - 1, 4));
        } else {
            $concat .= \chr(254);
            $concat .= \substr(\pack('V', $l), 0, 3);
            $concat .= $string;
            $concat .= \pack('@'.self::posmod(-$l, 4));
        }
        return $concat;
    }

}
