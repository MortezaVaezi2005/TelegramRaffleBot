<?php

declare(strict_types=1);

/**
 * Tools module.
 *
 * Copyright 2024 Daniil Gentili.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2024 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/license/apache-2-0 Apache 2.0
 * @link https://github.com/danog/telegram-entities TelegramEntities documentation
 */

namespace danog\TelegramEntities;

use Webmozart\Assert\Assert;

/**
 * Telegram UTF-16 styled text entity tools.
 *
 * @api
 */
final class EntityTools
{
    // @codeCoverageIgnoreStart
    /**
     * @psalm-suppress UnusedConstructor
     *
     * @internal Can only be used statically.
     */
    private function __construct()
    {
    }
    // @codeCoverageIgnoreEnd

    /**
     * Get length of string in UTF-16 code points.
     *
     * @param string $text Text
     */
    public static function mbStrlen(string $text): int
    {
        $length = 0;
        $textlength = \strlen($text);
        for ($x = 0; $x < $textlength; $x++) {
            $char = \ord($text[$x]);
            if (($char & 0xc0) != 0x80) {
                $length += 1 + ($char >= 0xf0 ? 1 : 0);
            }
        }
        return $length;
    }
    /**
     * Telegram UTF-16 multibyte substring.
     *
     * @param string   $text   Text to substring
     * @param integer  $offset Offset
     * @param null|int $length Length
     */
    public static function mbSubstr(string $text, int $offset, ?int $length = null): string
    {
        /** @var string */
        $converted = \mb_convert_encoding($text, 'UTF-16');
        /** @var string */
        return \mb_convert_encoding(
            \substr(
                $converted,
                $offset<<1,
                $length === null ? null : ($length<<1),
            ),
            'UTF-8',
            'UTF-16',
        );
    }
    /**
     * Telegram UTF-16 multibyte split.
     *
     * @param  string $text Text
     * @param  integer<0, max> $length Length
     * @return list<string>
     */
    public static function mbStrSplit(string $text, int $length): array
    {
        $result = [];
        /** @var string */
        $text = \mb_convert_encoding($text, 'UTF-16');
        /** @psalm-suppress ArgumentTypeCoercion */
        foreach (\str_split($text, $length<<1) as $chunk) {
            $chunk = \mb_convert_encoding($chunk, 'UTF-8', 'UTF-16');
            Assert::string($chunk);
            $result []= $chunk;
        }
        /** @var list<string> */
        return $result;
    }
    /**
     * Escape string for this library's HTML entity converter.
     *
     * @param string $what String to escape
     */
    public static function htmlEscape(string $what): string
    {
        return \htmlspecialchars($what, ENT_QUOTES|ENT_SUBSTITUTE|ENT_XML1);
    }
    /**
     * Escape string for markdown.
     *
     * @param string $what String to escape
     */
    public static function markdownEscape(string $what): string
    {
        return \str_replace(
            [
                '\\',
                '_',
                '*',
                '[',
                ']',
                '(',
                ')',
                '~',
                '`',
                '>',
                '#',
                '+',
                '-',
                '=',
                '|',
                '{',
                '}',
                '.',
                '!',
            ],
            [
                '\\\\',
                '\\_',
                '\\*',
                '\\[',
                '\\]',
                '\\(',
                '\\)',
                '\\~',
                '\\`',
                '\\>',
                '\\#',
                '\\+',
                '\\-',
                '\\=',
                '\\|',
                '\\{',
                '\\}',
                '\\.',
                '\\!',
            ],
            $what
        );
    }
    /**
     * Escape string for markdown codeblock.
     *
     * @param string $what String to escape
     */
    public static function markdownCodeblockEscape(string $what): string
    {
        return \str_replace('```', '\\```', $what);
    }
    /**
     * Escape string for markdown code section.
     *
     * @param string $what String to escape
     */
    public static function markdownCodeEscape(string $what): string
    {
        return \str_replace('`', '\\`', $what);
    }
    /**
     * Escape string for URL.
     *
     * @param string $what String to escape
     */
    public static function markdownUrlEscape(string $what): string
    {
        return \str_replace(')', '\\)', $what);
    }
}
