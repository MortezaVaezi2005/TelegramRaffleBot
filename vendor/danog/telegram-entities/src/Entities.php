<?php declare(strict_types=1);

/**
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

use AssertionError;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Class that represents a message + set of Telegram entities.
 *
 * @api
 *
 * @psalm-type TEntity=(
 *      array{
 *          type: "bold"|"italic"|"code"|"strikethrough"|"underline"|"block_quote"|"url"|"email"|"phone"|"spoiler"|"mention",
 *          offset: int<0, max>,
 *          length: int<0, max>
 *      }
 *      |array{type: "text_mention", user: array{id: int, ...}, offset: int, length: int}
 *      |array{type: "custom_emoji", custom_emoji_id: int, offset: int, length: int}
 *      |array{type: "pre", language?: string, offset: int, length: int}
 *      |array{type: "text_link", url: string, offset: int, length: int}
 * )
 */
final class Entities
{
    /**
     * Creates an Entities container using a message and a list of entities.
     */
    public function __construct(
        /** Converted message */
        public string $message,
        /**
         * Converted entities.
         *
         * @var list<TEntity>
         */
        public array $entities,
    ) {
    }

    /**
     * Manually convert markdown to a message and a set of entities.
     *
     * @return Entities Object containing message and entities
     */
    public static function fromMarkdown(string $markdown): self
    {
        $markdown = \trim(\str_replace("\r\n", "\n", $markdown));
        $message = '';
        $messageLen = 0;
        $entities = [];
        $offset = 0;
        $stack = [];
        while ($offset < \strlen($markdown)) {
            $len = \strcspn($markdown, '*_~`[]|!\\', $offset);
            $piece = \substr($markdown, $offset, $len);
            $offset += $len;
            if ($offset === \strlen($markdown)) {
                $message .= $piece;
                break;
            }

            $char = $markdown[$offset++];
            $next = $markdown[$offset] ?? '';
            if ($char === '\\') {
                $message .= $piece.$next;
                $messageLen += EntityTools::mbStrlen($piece)+1;
                $offset++;
                continue;
            }

            if ($char === '_' && $next === '_') {
                $offset++;
                $char = '__';
            } elseif ($char === '|') {
                if ($next === '|') {
                    $offset++;
                    $char = '||';
                } else {
                    $message .= $piece.$char;
                    $messageLen += EntityTools::mbStrlen($piece)+1;
                    continue;
                }
            } elseif ($char === '!') {
                if ($next === '[') {
                    $offset++;
                    $char = '](';
                } else {
                    $message .= $piece.$char;
                    $messageLen += EntityTools::mbStrlen($piece)+1;
                    continue;
                }
            } elseif ($char === '[') {
                $char = '](';
            } elseif ($char === ']') {
                if (!$stack || \end($stack)[0] !== '](') {
                    $message .= $piece.$char;
                    $messageLen += EntityTools::mbStrlen($piece)+1;
                    continue;
                }
                if ($next !== '(') {
                    \array_pop($stack);
                    $message .= '['.$piece.$char;
                    $messageLen += EntityTools::mbStrlen($piece)+2;
                    continue;
                }
                $offset++;
                $char = "](";
            } elseif ($char === '`') {
                $message .= $piece;
                $messageLen += EntityTools::mbStrlen($piece);

                $token = '`';
                $language = null;
                if ($next === '`' && ($markdown[$offset+1] ?? '') === '`') {
                    $token = '```';

                    $offset += 2;
                    $langLen = \strcspn($markdown, "\n ", $offset);
                    $language = \substr($markdown, $offset, $langLen);
                    $offset += $langLen;
                    if (($markdown[$offset] ?? '') === "\n") {
                        $offset++;
                    }
                }

                $piece = '';
                $posClose = $offset;
                while (($posClose = \strpos($markdown, $token, $posClose)) !== false) {
                    if ($markdown[$posClose-1] === '\\') {
                        $piece .= \substr($markdown, $offset, ($posClose-$offset)-1).$token;
                        $posClose += \strlen($token);
                        $offset = $posClose;
                        continue;
                    }
                    break;
                }
                /** @var int|false $posClose */
                if ($posClose === false) {
                    throw new AssertionError("Unclosed ``` opened @ pos $offset!");
                }
                $piece .= \substr($markdown, $offset, $posClose-$offset);

                $start = $messageLen;

                $message .= $piece;
                $pieceLen = EntityTools::mbStrlen($piece);
                $messageLen += $pieceLen;

                for ($x = \strlen($piece)-1; $x >= 0; $x--) {
                    if (!(
                        $piece[$x] === ' '
                        || $piece[$x] === "\r"
                        || $piece[$x] === "\n"
                    )) {
                        break;
                    }
                    $pieceLen--;
                }
                if ($pieceLen > 0) {
                    \assert($start >= 0);
                    $tmp = [
                        'type' => match ($token) {
                            '```' => 'pre',
                            '`' => 'code',
                        },
                        'offset' => $start,
                        'length' => $pieceLen,
                    ];
                    if ($language !== null) {
                        $tmp['language'] = $language;
                    }
                    $entities []= $tmp;
                    unset($tmp);
                }

                $offset = $posClose+\strlen($token);
                continue;
            }

            if ($stack && \end($stack)[0] === $char) {
                [, $start] = \array_pop($stack);
                if ($char === '](') {
                    $posClose = $offset;
                    $link = '';
                    while (($posClose = \strpos($markdown, ')', $posClose)) !== false) {
                        if ($markdown[$posClose-1] === '\\') {
                            $link .= \substr($markdown, $offset, ($posClose-$offset)-1);
                            $offset = $posClose++;
                            continue;
                        }
                        $link .= \substr($markdown, $offset, ($posClose-$offset));
                        break;
                    }
                    /** @var int|false $posClose */
                    if ($posClose === false) {
                        throw new AssertionError("Unclosed ) opened @ pos $offset!");
                    }
                    $entity = self::handleLink($link);
                    $offset = $posClose+1;
                } else {
                    $entity = match ($char) {
                        '*' => ['type' => 'bold'],
                        '_' => ['type' => 'italic'],
                        '__' =>  ['type' => 'underline'],
                        '`' => ['type' => 'code'],
                        '~' => ['type' => 'strikethrough'],
                        '||' => ['type' => 'spoiler'],
                        default => throw new AssertionError("Unknown char $char @ pos $offset!")
                    };
                }
                $message .= $piece;
                $messageLen += EntityTools::mbStrlen($piece);

                $lengthReal = $messageLen-$start;
                for ($x = \strlen($message)-1; $x >= 0; $x--) {
                    if (!(
                        $message[$x] === ' '
                        || $message[$x] === "\r"
                        || $message[$x] === "\n"
                    )) {
                        break;
                    }
                    $lengthReal--;
                }
                if ($lengthReal > 0) {
                    $entities []= $entity + ['offset' => $start, 'length' => $lengthReal];
                }
            } else {
                $message .= $piece;
                $messageLen += EntityTools::mbStrlen($piece);
                $stack []= [$char, $messageLen];
            }
        }
        if ($stack) {
            throw new AssertionError("Found unclosed markdown elements ".\implode(', ', \array_column($stack, 0)));
        }
        /** @psalm-suppress MixedArgumentTypeCoercion Psalm bug to fix */
        return new Entities(
            \trim($message),
            $entities,
        );
    }

    /**
     * Manually convert HTML to a message and a set of entities.
     *
     * @return Entities Object containing message and entities
     */
    public static function fromHtml(string $html): Entities
    {
        $dom = new DOMDocument();
        $html = \preg_replace('/\<br(\s*)?\/?\>/i', "\n", $html);
        \assert($html !== null);
        $dom->loadxml('<body>' . \trim($html) . '</body>');
        $message = '';
        $entities = [];
        /** @psalm-suppress PossiblyNullArgument Ignore, will throw anyway */
        self::parseNode($dom->getElementsByTagName('body')->item(0), 0, $message, $entities);
        return new Entities(\trim($message), $entities);
    }
    /**
     * @return integer Length of the node
     *
     * @psalm-suppress UnusedReturnValue
     *
     * @param-out list<TEntity> $entities
     * @param list<TEntity> $entities
     */
    private static function parseNode(DOMNode|DOMText $node, int $offset, string &$message, array &$entities): int
    {
        if ($node instanceof DOMText) {
            $message .= $node->wholeText;
            return EntityTools::mbStrlen($node->wholeText);
        }
        // @codeCoverageIgnoreStart
        if ($node->nodeName === 'br') {
            $message .= "\n";
            return 1;
        }
        // @codeCoverageIgnoreEnd
        /** @var DOMElement $node */
        $entity = match ($node->nodeName) {
            's', 'strike', 'del' => ['type' => 'strikethrough'],
            'u' =>  ['type' => 'underline'],
            'blockquote' => ['type' => 'block_quote'],
            'b', 'strong' => ['type' => 'bold'],
            'i', 'em' => ['type' => 'italic'],
            'code' => ['type' => 'code'],
            'spoiler', 'tg-spoiler' => ['type' => 'spoiler'],
            'pre' => $node->hasAttribute('language')
                ? ['type' => 'pre', 'language' => $node->getAttribute('language')]
                : ['type' => 'pre'],
            'span' => $node->hasAttribute('class') && $node->getAttribute('class') === 'tg-spoiler'
                    ? ['type' => 'spoiler']
                    : null,
            'tg-emoji' => ['type' => 'custom_emoji', 'custom_emoji_id' => (int) $node->getAttribute('emoji-id')],
            'emoji' => ['type' => 'custom_emoji', 'custom_emoji_id' => (int) $node->getAttribute('id')],
            'a' => self::handleLink($node->getAttribute('href')),
            default => null,
        };
        $length = 0;
        /** @var DOMNode|DOMText */
        foreach ($node->childNodes as $sub) {
            $length += self::parseNode($sub, $offset+$length, $message, $entities);
        }
        if ($entity !== null) {
            $lengthReal = $length;
            for ($x = \strlen($message)-1; $x >= 0; $x--) {
                if (!(
                    $message[$x] === ' '
                    || $message[$x] === "\r"
                    || $message[$x] === "\n"
                )) {
                    break;
                }
                $lengthReal--;
            }
            if ($lengthReal > 0) {
                \assert($offset >= 0);
                $entity['offset'] = $offset;
                $entity['length'] = $lengthReal;
                /** @psalm-check-type $entity = TEntity */
                $entities []= $entity;
            }
        }
        return $length;
    }
    /** @return array{type: "text_mention", user: array{id: int}}|array{type: "custom_emoji", custom_emoji_id: int}|array{type: "text_link", url: string} */
    private static function handleLink(string $href): array
    {
        if (\preg_match('|^mention:(.+)|', $href, $matches) || \preg_match('|^tg://user\\?id=(.+)|', $href, $matches)) {
            return ['type' => 'text_mention', 'user' => ['id' => (int) $matches[1]]];
        }
        if (\preg_match('|^emoji:(\d+)$|', $href, $matches) || \preg_match('|^tg://emoji\\?id=(.+)|', $href, $matches)) {
            return ['type' => 'custom_emoji', 'custom_emoji_id' => (int) $matches[1]];
        }
        return ['type' => 'text_link', 'url' => $href];
    }
    /**
     * Convert a message and a set of entities to HTML.
     *
     * @param bool $allowTelegramTags Whether to allow telegram-specific tags like tg-spoiler, tg-emoji, mention links and so on...
     */
    public function toHTML(bool $allowTelegramTags = false): string
    {
        $insertions = [];
        foreach ($this->entities as $entity) {
            ['offset' => $offset, 'length' => $length] = $entity;
            $insertions[$offset] ??= '';
            /** @psalm-suppress PossiblyUndefinedArrayOffset, DocblockTypeContradiction */
            $insertions[$offset] .= match ($entity['type']) {
                'bold' => '<b>',
                'italic' => '<i>',
                'code' => '<code>',
                'pre' => isset($entity['language']) && $entity['language'] !== '' ? '<pre language="'.$entity['language'].'">' : '<pre>',
                'text_link' => '<a href="'.EntityTools::htmlEscape($entity['url']).'">',
                'strikethrough' => '<s>',
                "underline" => '<u>',
                "block_quote" => '<blockquote>',
                "url" => '<a href="'.EntityTools::htmlEscape(EntityTools::mbSubstr($this->message, $offset, $length)).'">',
                "email" => '<a href="mailto:'.EntityTools::htmlEscape(EntityTools::mbSubstr($this->message, $offset, $length)).'">',
                "phone" => '<a href="phone:'.EntityTools::htmlEscape(EntityTools::mbSubstr($this->message, $offset, $length)).'">',
                "mention" => '<a href="https://t.me/'.EntityTools::htmlEscape(EntityTools::mbSubstr($this->message, $offset+1, $length-1)).'">',
                "spoiler" => $allowTelegramTags ? '<tg-spoiler>' : '<span class="tg-spoiler">',
                "custom_emoji" => $allowTelegramTags ? '<tg-emoji emoji-id="'.$entity['custom_emoji_id'].'">' : '',
                "text_mention" => $allowTelegramTags ? '<a href="tg://user?id='.$entity['user']['id'].'">' : '',
                default => '',
            };
            $offset += $length;
            /** @psalm-suppress DocblockTypeContradiction */
            $insertions[$offset] = match ($entity['type']) {
                "bold" => '</b>',
                "italic" => '</i>',
                "code" => '</code>',
                "pre" => '</pre>',
                "text_link", "url", "email", "mention", "phone" => '</a>',
                "strikethrough" => '</s>',
                "underline" => '</u>',
                "block_quote" => '</blockquote>',
                "spoiler" => $allowTelegramTags ? '</tg-spoiler>' : '</span>',
                "custom_emoji" => $allowTelegramTags ? "</tg-emoji>" : '',
                "text_mention" => $allowTelegramTags ? '</a>' : '',
                default => '',
            } . ($insertions[$offset] ?? '');
        }
        \ksort($insertions);
        $final = '';
        $pos = 0;
        foreach ($insertions as $offset => $insertion) {
            $final .= EntityTools::htmlEscape(EntityTools::mbSubstr($this->message, $pos, $offset-$pos));
            $final .= $insertion;
            $pos = $offset;
        }
        return \str_replace("\n", "<br>", $final.EntityTools::htmlEscape(EntityTools::mbSubstr($this->message, $pos)));
    }
}
