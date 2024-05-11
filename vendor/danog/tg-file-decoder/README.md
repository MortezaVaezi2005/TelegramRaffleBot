# tg-file-decoder

![build](https://github.com/danog/tg-file-decoder/workflows/build/badge.svg)
[![Psalm coverage](https://shepherd.dev/github/danog/tg-file-decoder/coverage.svg)](https://shepherd.dev/github/danog/tg-file-decoder)
[![Psalm level 1](https://shepherd.dev/github/danog/tg-file-decoder/level.svg)](https://shepherd.dev/github/danog/tg-file-decoder)

Decode and encode [Telegram bot API file IDs](https://core.telegram.org)!  

This library was initially created for [MadelineProto](https://docs.madelineproto.xyz), an async PHP client API for the telegram MTProto protocol.

## Install

```bash
composer require danog/tg-file-decoder
```

## Examples:

### Decoding bot API file IDs

```php
use danog\Decoder\FileId;
use danog\Decoder\UniqueFileId;

$fileId = FileId::fromBotAPI('CAACAgQAAxkDAAJEsl44nl3yxPZ8biI8uhaA7rbQceOSAAKtAQACsTisUXvMEbVnTuQkGAQ');

$version = $fileId->version; // bot API file ID version, usually 4
$subVersion = $fileId->subVersion; // bot API file ID subversion, equivalent to a specific tdlib version

$dcId = $fileId->dcId; // On which datacenter is this file stored

$type = $fileId->type; // File type

$id = $fileId->id;
$accessHash = $fileId->accessHash;

$fileReference = $fileId->fileReference; // File reference, https://core.telegram.org/api/file_reference
$url = $fileId->url; // URL, file IDs with encoded webLocation

// You can also use hasFileReference and hasUrl
$fileIdReencoded = $fileId->getBotAPI(); // CAACAgQAAxkDAAJEsl44nl3yxPZ8biI8uhaA7rbQceOSAAKtAQACsTisUXvMEbVnTuQkGAQ
$fileIdReencoded = (string) $fileId;     // CAACAgQAAxkDAAJEsl44nl3yxPZ8biI8uhaA7rbQceOSAAKtAQACsTisUXvMEbVnTuQkGAQ

// For photos, thumbnails if ($fileId->getType() <= FileIdType::PHOTO->value)
$volumeId = $fileId->volumeId;
$localId = $fileId->localId;

$photoSizeSource = $fileId->photoSizeSource; // PhotoSizeSource object
$photoSizeSource->dialogId;
$photoSizeSource->stickerSetId;

// And more, depending on photosize source
```

The bot API subversion, present since file IDs v4, is equivalent to the [version of tdlib](https://github.com/tdlib/td/blob/master/td/telegram/Version.h#L13) used server-side in the bot API.

For file types, see [file types](#bot-api-file-id-types).
For photosize source, see [here](#photosize-source).

### Decoding bot API unique file IDs

```php
$uniqueFileId = UniqueFileId::fromUniqueBotAPI('AgADrQEAArE4rFE');

$type = $fileId->type; // Unique file type

$id = $uniqueFileId->id;
$url = $uniqueFileId->url; // URL, for unique file IDs with encoded webLocation

// For photos
$volumeId = $uniqueFileId->volumeId;
$localId = $uniqueFileId->localId;
```

For unique file types, see [unique types](#bot-api-unique-file-id-types).

### Extracting unique file IDs from full file IDs

```php
$full = 'CAACAgQAAxkDAAJEsl44nl3yxPZ8biI8uhaA7rbQceOSAAKtAQACsTisUXvMEbVnTuQkGAQ';
$unique = 'AgADrQEAArE4rFE';

$fileId = FileId::fromBotAPI($full);
$uniqueFileId = UniqueFileId::fromUniqueBotAPI($unique);
$uniqueFileIdExtracted1 = UniqueFileId::fromBotAPI($full);

$uniqueFileIdExtracted2 = $fileId->getUniqueBotAPI();

var_dump(((string) $uniqueFileId) === ((string) $uniqueFileIdExtracted1)); // true, always AgADrQEAArE4rFE!
var_dump(((string) $uniqueFileId) === ((string) $uniqueFileIdExtracted2)); // true, always AgADrQEAArE4rFE!
```

### Photosize source

```php
$fileId = FileId::fromString('CAACAgQAAxkDAAJEsl44nl3yxPZ8biI8uhaA7rbQceOSAAKtAQACsTisUXvMEbVnTuQkGAQ');

$photoSizeSource = $fileId->photoSizeSource; // PhotoSizeSource object

$sourceType = $photoSizeSource->type;

// If $sourceType === PHOTOSIZE_SOURCE_DIALOGPHOTO_SMALL|PHOTOSIZE_SOURCE_DIALOGPHOTO_SMALL or 
// If $photoSizeSource instanceof PhotoSizeSourceDialogPhoto
$dialogId = $photoSizeSource->dialogId;
$dialogId = $photoSizeSource->sticketSetId;
```

The `PhotoSizeSource` abstract base class indicates the source of a specific photosize from a chat photo, photo, stickerset thumbnail, file thumbnail.

Click [here &raquo;](https://github.com/danog/tg-file-decoder/blob/master/docs/index.md) to view the full list of `PhotoSizeSource` types.  

### Building custom file IDs

```php
$fileId = new FileId(
    type: FileIdType::STICKER,
    id: $id,
    accessHash: $accessHash,
    // and so on...
);

$encoded = (string) $fileId; // CAACAgQAAxkDAAJEsl44nl3yxPZ8biI8uhaA7rbQceOSAAKtAQACsTisUXvMEbVnTuQkGAQ, or something
```

### Bot API file ID types

The file type is a PHP enum indicating the type of file, [danog\Decoder\FileIdType](https://github.com/danog/tg-file-decoder/blob/master/docs/danog/Decoder/FileIdType.md).  

Click [here &raquo;](https://github.com/danog/tg-file-decoder/blob/master/docs/danog/Decoder/FileIdType.md) to view the full list of file ID types.  

The enum also offers a `FileIdType::from` method that can be used to obtain the correct case, from a string version of the file type, typically the one used in bot API file objects.  

### Bot API unique file ID types

The unique file type is a PHP enum uniquely indicating the unique file, [danog\Decoder\UniqueFileIdType](https://github.com/danog/tg-file-decoder/blob/master/docs/danog/Decoder/UniqueFileIdType.md).  

Click [here &raquo;](https://github.com/danog/tg-file-decoder/blob/master/docs/danog/Decoder/UniqueFileIdType.md) to view the full list of file ID types.  


## Full API documentation

Click [here &raquo;](https://github.com/danog/tg-file-decoder/blob/master/docs/index.md) to view the full API documentation.  