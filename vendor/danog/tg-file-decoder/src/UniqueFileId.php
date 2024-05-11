<?php declare(strict_types=1);
/**
 * Decoded UniqueFileId class.
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
use danog\Decoder\PhotoSizeSource\PhotoSizeSourceStickersetThumbnailVersion;
use danog\Decoder\PhotoSizeSource\PhotoSizeSourceThumbnail;

/**
 * Represents decoded unique bot API file ID.
 *
 * @api
 */
final class UniqueFileId
{
    /**
     * Basic constructor function.
     */
    public function __construct(
        /**
         * File type.
         *
         */
        public UniqueFileIdType $type,
        /**
         * File ID.
         *
         */
        public ?int $id = null,
        /**
         * Photo subtype.
         *
         */
        public ?int $subType = null,
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
         * Sticker set ID.
         *
         */
        public ?int $stickerSetId = null,
        /**
         * Sticker set version.
         *
         */
        public ?int $stickerSetVersion = null,
        /**
         * Weblocation URL.
         *
         */
        public ?string $url= null,
    ) {
    }

    /**
     * Get unique bot API file ID.
     *
     */
    public function __toString(): string
    {
        return $this->getUniqueBotAPI();
    }

    /**
     * Get unique bot API file ID.
     *
     */
    public function getUniqueBotAPI(): string
    {
        $fileId = \pack('V', $this->type->value);
        if ($this->url !== null) {
            $fileId .= Tools::packTLString($this->url);
        } elseif ($this->type === UniqueFileIdType::PHOTO) {
            if ($this->volumeId !== null) {
                $fileId .= Tools::packLong($this->volumeId);
                $fileId .= \pack('l', $this->localId);
            } elseif ($this->stickerSetId !== null) {
                \assert($this->subType !== null);
                $fileId .= \chr($this->subType);
                $fileId .= Tools::packLong($this->stickerSetId);
                $fileId .= \pack('l', $this->stickerSetVersion);
            } else {
                \assert($this->subType !== null && $this->id !== null);
                $fileId .= Tools::packLong($this->id);
                $fileId .= \chr($this->subType);
            }
        } elseif ($this->id !== null) {
            $fileId .= Tools::packLong($this->id);
        }

        return Tools::base64urlEncode(Tools::rleEncode($fileId));
    }

    /**
     * Decode unique bot API file ID.
     *
     * @param string $fileId Bot API file ID
     *
     */
    public static function fromUniqueBotAPI(string $fileId): self
    {
        $orig = $fileId;
        $fileId = Tools::rleDecode(Tools::base64urlDecode($fileId));

        /** @var int */
        $typeId = \unpack('V', $fileId)[1];
        $type = UniqueFileIdType::from($typeId);
        $url = null;

        $subType = null;
        $id = null;
        $fileId = \substr($fileId, 4);
        $volume_id = null;
        $local_id = null;
        $sticker_set_id = null;
        $sticker_set_version = null;
        if ($type === UniqueFileIdType::WEB) {
            $res = \fopen('php://memory', 'rw+b');
            \assert($res !== false);
            \fwrite($res, $fileId);
            \fseek($res, 0);
            $fileId = $res;
            $url = Tools::readTLString($fileId);

            $l = \fstat($fileId)['size'] - \ftell($fileId);
            \trigger_error("Unique file ID $orig has $l bytes of leftover data");
        } elseif (\strlen($fileId) === 12) {
            // Legacy photos
            $volume_id = Tools::unpackLong(\substr($fileId, 0, 8));
            $local_id = Tools::unpackInt(\substr($fileId, 8));
        } elseif (\strlen($fileId) === 9) {
            // Dialog photos/thumbnails
            $id = Tools::unpackLong($fileId);
            $subType = \ord($fileId[8]);
        } elseif (\strlen($fileId) === 13) {
            // Stickerset ID/version
            $subType = \ord($fileId[0]);
            $sticker_set_id = Tools::unpackLong(\substr($fileId, 1, 8));
            $sticker_set_version = Tools::unpackInt(\substr($fileId, 9));
        } elseif (\strlen($fileId) === 8) {
            // Any other document
            $id = Tools::unpackLong($fileId);
        } else {
            $l = \strlen($fileId);
            \trigger_error("Unique file ID $orig has $l bytes of leftover data");
        }
        return new self(
            type: $type,
            id: $id,
            subType: $subType,
            volumeId: $volume_id,
            localId: $local_id,
            stickerSetId: $sticker_set_id,
            stickerSetVersion: $sticker_set_version,
            url: $url
        );
    }

    /**
     * Convert full bot API file ID to unique file ID.
     *
     * @param string $fileId Full bot API file ID
     *
     */
    public static function fromBotAPI(string $fileId): self
    {
        return FileId::fromBotAPI($fileId)->getUnique();
    }

    /**
     * Turn full file ID into unique file ID.
     *
     * @param FileId $fileId Full file ID
     *
     */
    public static function fromFileId(FileId $fileId): self
    {
        if ($fileId->url !== null) {
            return new self(
                UniqueFileIdType::WEB,
                url: $fileId->url
            );
        }
        $type = $fileId->type->toUnique();
        if ($type === UniqueFileIdType::PHOTO) {
            $photoSize = $fileId->photoSizeSource;
            $subType = null;
            if ($photoSize instanceof PhotoSizeSourceThumbnail) {
                $subType = \ord($photoSize->thumbType);
                if ($subType === 97) {
                    $subType = 0;
                } elseif ($subType === 99) {
                    $subType = 1;
                } else {
                    $subType = $subType+5;
                }
                $subType = $subType;
            } elseif ($photoSize instanceof PhotoSizeSourceDialogPhoto) {
                $subType = $photoSize->isSmallDialogPhoto() ? 0 : 1;
            } elseif ($photoSize instanceof PhotoSizeSourceStickersetThumbnailVersion) {
                return new self(
                    $type,
                    $fileId->id,
                    2,
                    stickerSetId: $photoSize->stickerSetId,
                    stickerSetVersion: $photoSize->stickerSetVersion,
                );
            }
            return new self(
                $type,
                $fileId->id,
                $subType,
                $fileId->volumeId,
                $fileId->localId,
            );
        }
        return new self($type, $fileId->id);
    }
}
