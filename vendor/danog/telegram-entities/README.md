# telegram-entities

[![codecov](https://codecov.io/gh/danog/telegram-entities/branch/master/graph/badge.svg)](https://codecov.io/gh/danog/telegram-entities)
[![Psalm coverage](https://shepherd.dev/github/danog/telegram-entities/coverage.svg)](https://shepherd.dev/github/danog/telegram-entities)
[![Psalm level 1](https://shepherd.dev/github/danog/telegram-entities/level.svg)](https://shepherd.dev/github/danog/telegram-entities)
![License](https://img.shields.io/github/license/danog/telegram-entities)

A library to work with Telegram UTF-16 styled text entities, created by Daniil Gentili (https://daniil.it).  

This library can be used to modify entities returned by the Telegram Bot API, convert entities to HTML or even locally generate them using a custom MarkdownV2 and HTML parser inside of the library.  

This library was initially created for [MadelineProto](https://docs.madelineproto.xyz), an async PHP client API for the telegram MTProto protocol.  

## Installation

```bash
composer require danog/telegram-entities
```

## Usage

```php
<?php declare(strict_types=1);

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use danog\TelegramEntities\Entities;
use danog\TelegramEntities\EntityTools;

require __DIR__.'/../vendor/autoload.php';

$token = getenv('TOKEN');
if (!$token) {
    throw new AssertionError("A TOKEN environment variable must be specified!");
}

$dest = getenv('DEST');
if (!$dest) {
    throw new AssertionError("A DEST environment variable must be specified!");
}

$client = HttpClientBuilder::buildDefault();

$sm = function (string $message, string $parse_mode = '', array $entities = []) use ($token, $dest, $client): array {
    $res = $client->request(new Request("https://api.telegram.org/bot$token/sendMessage?".http_build_query([
        'text' => $message,
        'parse_mode' => $parse_mode,
        'entities' => json_encode($entities),
        'chat_id' => $dest
    ])));

    return json_decode($res->getBody()->buffer(), true)['result'];
};

$result = $sm("*This is a ❤️ test*", parse_mode: "MarkdownV2");

// Convert a message+entities back to HTML
$entities = new Entities($result['text'], $result['entities']);
var_dump($entities->toHTML()); // <b>This is a ❤️ test</b>

// Modify $entities as needed
$entities->message = "A message with ❤️ emojis";

// EntityTools::mb* methods compute the length in UTF-16 code units, as required by the bot API.
$entities->entities[0]['length'] = EntityTools::mbStrlen($entities->message);

// then resend:
$sm($entities->message, entities: $entities->entities);

// Convert HTML to an array of entities locally
$entities = Entities::fromHtml("<b>This is <i>a ❤️ nested</i> test</b>");
$sm($entities->message, entities: $entities->entities);

// Convert markdown to an array of entities locally
$entities = Entities::fromMarkdown("*This is _a ❤️ nested_ test*");
$sm($entities->message, entities: $entities->entities);

// Escape text using utility methods
$generic = EntityTools::markdownEscape("Automatically escaped to prevent *markdown injection*!");
$link = EntityTools::markdownUrlEscape("https://google.com");
$code = EntityTools::markdownCodeEscape("test with autoescaped ` test");
$codeBlock = EntityTools::markdownCodeblockEscape("<?php echo 'test with autoescaped ``` test';");

$entities = Entities::fromMarkdown("This is _a ❤️ [nested]($link)_ `$code`

\```php
$codeBlock
\```

$generic
");

$sm($entities->message, entities: $entities->entities);

// Escape text for the HTML parser!
$generic = EntityTools::htmlEscape("Automatically escaped to prevent <b>HTML injection</b>!");
$entities = Entities::fromHtml($generic);

$sm($entities->message, entities: $entities->entities);

// See https://github.com/danog/telegram-entities for the full list of available methods!
```

Many more methods are available, see the [API documentation](https://github.com/danog/telegram-entities/blob/master/docs/docs/index.md) for the full list!

## API Documentation

Click [here &raquo;](https://github.com/danog/telegram-entities/blob/master/docs/docs/index.md) to view the API documentation.
