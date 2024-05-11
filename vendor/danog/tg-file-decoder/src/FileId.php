<?php declare(strict_types=1);
/**
 * Decoded FileId class.
 *
 * This file is part of tg-file-decoder.
 * tg-file-decoder is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * tg-file-decoder is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with tg-file-decoder.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2019 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 *
 * @link https://github.com/tg-file-decoder Documentation
 */

namespace danog\Decoder;

use danog\Decoder\PhotoSizeSource\PhotoSizeSourceDialogPhoto;
use danog\Decoder\PhotoSizeSource\PhotoSizeSourceDialogPhotoBig;
use danog\Decoder\PhotoSizeSource\PhotoSizeSourceDialogPhotoSmall;
use danog\Decoder\PhotoSizeSource\PhotoSizeSourceLegacy;
use danog\Decoder\PhotoSizeSource\PhotoSizeSourceStickersetThumbnail;
use danog\Decoder\PhotoSizeSource\PhotoSizeSourceStickersetThumbnailVersion;
use danog\Decoder\PhotoSizeSource\PhotoSizeSourceThumbnail;

/**
 * Represents decoded bot API file ID.
 *
 * @api
 */
final class FileId
{
    /**
     * Basic constructor function.
     */
    public function __construct(
        /**
         * DC ID.
         *
         */
        public int $dcId,

        /**
         * File type.
         *
         */
        public FileIdType $type,

        /**
         * File id.
         *
         */
        public ?int $id,
        /**
         * File access hash.
         *
         */
        public int $accessHash,

        /**
         * Photo size source.
         *
         */
        public ?PhotoSizeSource $photoSizeSource = null,

        /**
         * Photo volume ID.
         *
         */
        public ?int $volumeId = null,
        /**
         * Photo local ID.
         *
         */
        public ?int $localId = null,

        /**
         * File reference.
         *
         */
        public ?string $fileReference = null,
        /**
         * File URL for weblocation.
         *
         */
        public ?string $url = null,

        /**
         * Bot API file ID version.
         *
         */
        public int $version = 4,

        /**
         * Bot API file ID subversion.
         *
         */
        public int $subVersion = 47,
    ) {
    }

    /**
     * Decode file ID from bot API file ID.
     *
     * @param string $fileId File ID
     *
     */
    public static function fromBotAPI(string $fileId): self
    {
        $orig = $fileId;
        $fileId = Tools::rleDecode(Tools::base64urlDecode($fileId));
        $version = \ord($fileId[\strlen($fileId) - 1]);
        $subVersion = $version === 4 ? \ord($fileId[\strlen($fileId) - 2]) : 0;

        $res = \fopen('php://memory', 'rw+b');
        \assert($res !== false);
        \fwrite($res, $fileId);
        \fseek($res, 0);
        $fileId = $res;
        $read = function (int $length) use (&$fileId): string {
            $res = \stream_get_contents($fileId, $length);
            \assert($res !== false);
            return $res;
        };

        $typeId = Tools::unpackInt($read(4));
        $dc_id = Tools::unpackInt($read(4));
        $fileReference = $typeId & Tools::FILE_REFERENCE_FLAG ? Tools::readTLString($fileId) : null;
        $hasWebLocation = (bool) ($typeId & Tools::WEB_LOCATION_FLAG);
        $typeId &= ~Tools::FILE_REFERENCE_FLAG;
        $typeId &= ~Tools::WEB_LOCATION_FLAG;

        if ($hasWebLocation) {
            $url = Tools::readTLString($fileId);
            $access_hash = Tools::unpackLong($read(8));
            return new self(
                $dc_id,
                FileIdType::fromInnerId($typeId),
                null,
                $access_hash,
                fileReference: $fileReference,
                url: $url,
                version: $version,
                subVersion: $subVersion
            );
        }
        $id = Tools::unpackLong($read(8));
        $access_hash = Tools::unpackLong($read(8));

        $volume_id = null;
        $local_id = null;
        $photoSizeSource = null;
        if ($typeId <= FileIdType::PHOTO->toInnerID()) {
            if ($subVersion < 32) {
                $volume_id = Tools::unpackLong($read(8));
                $local_id = Tools::unpackInt($read(4));
            }

            /** @var int */
            $arg = $subVersion >= 4 ? \unpack('V', $read(4))[1] : 0;
            $photosize_source = PhotoSizeSourceType::from($arg);
            switch ($photosize_source) {
                case PhotoSizeSourceType::LEGACY:
                    $photoSizeSource = new PhotoSizeSourceLegacy(Tools::unpackLong($read(8)));
                    break;
                case PhotoSizeSourceType::FULL_LEGACY:
                    $volume_id = Tools::unpackLong($read(8));
                    $photoSizeSource = new PhotoSizeSourceLegacy(Tools::unpackLong($read(8)));
                    $local_id = Tools::unpackInt($read(4));
                    break;
                case PhotoSizeSourceType::THUMBNAIL:
                    /** @var array{file_type: int, thumbnail_type: string} */
                    $result = \unpack('Vfile_type/athumbnail_type', $read(8));
                    $photoSizeSource = new PhotoSizeSourceThumbnail(
                        FileIdType::fromInnerId($result['file_type']),
                        $result['thumbnail_type']
                    );
                    break;
                case PhotoSizeSourceType::DIALOGPHOTO_BIG:
                case PhotoSizeSourceType::DIALOGPHOTO_SMALL:
                    $clazz = $photosize_source === PhotoSizeSourceType::DIALOGPHOTO_SMALL
                        ? PhotoSizeSourceDialogPhotoSmall::class
                        : PhotoSizeSourceDialogPhotoBig::class;
                    $photoSizeSource = new $clazz(
                        Tools::unpackLong($read(8)),
                        Tools::unpackLong($read(8)),
                    );
                    break;
                case PhotoSizeSourceType::STICKERSET_THUMBNAIL:
                    $photoSizeSource = new PhotoSizeSourceStickersetThumbnail(
                        Tools::unpackLong($read(8)),
                        Tools::unpackLong($read(8))
                    );
                    break;
                case PhotoSizeSourceType::DIALOGPHOTO_BIG_LEGACY:
                case PhotoSizeSourceType::DIALOGPHOTO_SMALL_LEGACY:
                    $clazz = $photosize_source === PhotoSizeSourceType::DIALOGPHOTO_SMALL_LEGACY
                        ? PhotoSizeSourceDialogPhotoSmall::class
                        : PhotoSizeSourceDialogPhotoBig::class;
                    $photoSizeSource = new $clazz(
                        Tools::unpackLong($read(8)),
                        Tools::unpackLong($read(8))
                    );

                    $volume_id = Tools::unpackLong($read(8));
                    $local_id = Tools::unpackInt($read(4));
                    break;
                case PhotoSizeSourceType::STICKERSET_THUMBNAIL_LEGACY:
                    $photoSizeSource = new PhotoSizeSourceStickersetThumbnail(
                        Tools::unpackLong($read(8)),
                        Tools::unpackLong($read(8)),
                    );

                    $volume_id = Tools::unpackLong($read(8));
                    $local_id = Tools::unpackInt($read(4));
                    break;
                case PhotoSizeSourceType::STICKERSET_THUMBNAIL_VERSION:
                    $photoSizeSource = new PhotoSizeSourceStickersetThumbnailVersion(
                        Tools::unpackLong($read(8)),
                        Tools::unpackLong($read(8)),
                        Tools::unpackInt($read(4))
                    );
                    break;
            }
        }
        $l = \fstat($fileId)['size'] - \ftell($fileId);
        $l -= $version >= 4 ? 2 : 1;
        if ($l > 0) {
            \trigger_error("File ID $orig has $l bytes of leftover data");
        }

        return new self(
            dcId: $dc_id,
            type: FileIdType::fromInnerId($typeId),
            id: $id,
            accessHash: $access_hash,
            volumeId: $volume_id,
            localId: $local_id,
            fileReference: $fileReference,
            version: $version,
            subVersion: $subVersion,
            photoSizeSource: $photoSizeSource,
        );
    }

    /**
     * Get bot API file ID.
     *
     */
    public function getBotAPI(): string
    {
        $type = $this->type->toInnerID();
        if ($this->fileReference !== null) {
            $type |= Tools::FILE_REFERENCE_FLAG;
        }
        if ($this->url !== null) {
            $type |= Tools::WEB_LOCATION_FLAG;
        }

        $fileId = \pack('VV', $type, $this->dcId);
        if ($this->fileReference !== null) {
            $fileId .= Tools::packTLString($this->fileReference);
        }
        if ($this->url !== null) {
            $fileId .= Tools::packTLString($this->url);
            $fileId .= Tools::packLong($this->accessHash);
            return Tools::base64urlEncode(Tools::rleEncode($fileId));
        }

        \assert($this->id !== null);
        $fileId .= Tools::packLong($this->id);
        $fileId .= Tools::packLong($this->accessHash);

        if ($this->photoSizeSource !== null) {
            $photoSize = $this->photoSizeSource;
            $writeExtra = false;
            switch (true) {
                case $photoSize instanceof PhotoSizeSourceLegacy:
                    if ($this->volumeId === null) {
                        $writeExtra = true;
                        $fileId .= \pack('V', PhotoSizeSourceType::LEGACY->value);
                        $fileId .= Tools::packLong($photoSize->secret);
                    } else {
                        $fileId .= \pack('V', PhotoSizeSourceType::FULL_LEGACY->value);
                        $fileId .= Tools::packLong($this->volumeId);
                        $fileId .= Tools::packLong($photoSize->secret);
                        $fileId .= \pack('l', $this->localId);
                    }
                    break;
                case $photoSize instanceof PhotoSizeSourceThumbnail:
                    $fileId .= \pack('V', PhotoSizeSourceType::THUMBNAIL->value);
                    $fileId .= \pack('Va4', $photoSize->thumbFileType->toInnerID(), $photoSize->thumbType);
                    break;
                case $photoSize instanceof PhotoSizeSourceDialogPhoto:
                    $fileId .= \pack(
                        'V',
                        ($writeExtra = $this->volumeId !== null) ?
                        (
                            $photoSize->isSmallDialogPhoto()
                            ? PhotoSizeSourceType::DIALOGPHOTO_SMALL_LEGACY->value
                            : PhotoSizeSourceType::DIALOGPHOTO_BIG_LEGACY->value
                        ) : (
                            $photoSize->isSmallDialogPhoto()
                            ? PhotoSizeSourceType::DIALOGPHOTO_SMALL->value
                            : PhotoSizeSourceType::DIALOGPHOTO_BIG->value
                        )
                    );
                    $fileId .= Tools::packLong($photoSize->dialogId);
                    $fileId .= Tools::packLong($photoSize->dialogAccessHash);
                    break;
                case $photoSize instanceof PhotoSizeSourceStickersetThumbnail:
                    $writeExtra = $this->volumeId !== null;
                    $fileId .= Tools::packLong($photoSize->stickerSetId);
                    $fileId .= Tools::packLong($photoSize->stickerSetAccessHash);
                    break;
                case $photoSize instanceof PhotoSizeSourceStickersetThumbnailVersion:
                    $fileId .= Tools::packLong($photoSize->stickerSetId);
                    $fileId .= Tools::packLong($photoSize->stickerSetAccessHash);
                    $fileId .= \pack('l', $photoSize->stickerSetVersion);
                    break;
            }
            if ($writeExtra && $this->volumeId !== null && $this->localId !== null) {
                $fileId .= Tools::packLong($this->volumeId);
                $fileId .= \pack('l', $this->localId);
            }
        }

        if ($this->version >= 4) {
            $fileId .= \chr($this->subVersion);
        }
        $fileId .= \chr($this->version);

        return Tools::base64urlEncode(Tools::rleEncode($fileId));
    }

    /**
     * Get unique file ID from file ID.
     *
     */
    public function getUnique(): UniqueFileId
    {
        return UniqueFileId::fromFileId($this);
    }
    /**
     * Get unique bot API file ID from file ID.
     *
     */
    public function getUniqueBotAPI(): string
    {
        return UniqueFileId::fromFileId($this)->getUniqueBotAPI();
    }
    /**
     * Get bot API file ID.
     *
     */
    public function __toString(): string
    {
        return $this->getBotAPI();
    }
}
