<?php declare(strict_types=1);
/**
 * Type enum + helper functions.
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

use AssertionError;

/**
 * Represents decoded bot API file ID type.
 *
 * @api
 */
enum FileIdType: string
{
    /**
     * Thumbnail.
     */
    case THUMBNAIL = 'thumbnail';
    /**
     * Profile photo.
     * Used for users and channels, chat photos are normal PHOTOs.
     */
    case PROFILE_PHOTO = 'profile_photo';
    /**
     * Normal photos.
     */
    case PHOTO = 'photo';

    /**
     * Voice messages.
     */
    case VOICE = 'voice';
    /**
     * Video.
     */
    case VIDEO = 'video';
    /**
     * Document.
     */
    case DOCUMENT = 'document';
    /**
     * Secret chat document.
     */
    case ENCRYPTED = 'encrypted';
    /**
     * Temporary document.
     */
    case TEMP = 'temp';
    /**
     * Sticker.
     */
    case STICKER = 'sticker';
    /**
     * Music.
     */
    case AUDIO = 'audio';
    /**
     * GIF.
     */
    case ANIMATION = 'animation';
    /**
     * Encrypted thumbnail.
     */
    case ENCRYPTED_THUMBNAIL = 'encrypted_thumbnail';
    /**
     * Wallpaper.
     */
    case WALLPAPER = 'wallpaper';
    /**
     * Round video.
     */
    case VIDEO_NOTE = 'video_note';
    /**
     * Passport raw file.
     */
    case SECURE_RAW = 'secure_raw';
    /**
     * Passport file.
     */
    case SECURE = 'secure';
    /**
     * Background.
     */
    case BACKGROUND = 'background';
    /**
     * Size.
     */
    case SIZE = 'size';

    /** @internal Should not be used manually. */
    /**
     * Obtain a bot API type ID.
     *
     * @internal Should not be used manually.
     */
    public static function fromInnerID(int $id): self
    {
        return match ($id) {
            0 => self::THUMBNAIL,
            1 => self::PROFILE_PHOTO,
            2 => self::PHOTO,
            3 => self::VOICE,
            4 => self::VIDEO,
            5 => self::DOCUMENT,
            6 => self::ENCRYPTED,
            7 => self::TEMP,
            8 => self::STICKER,
            9 => self::AUDIO,
            10 => self::ANIMATION,
            11 => self::ENCRYPTED_THUMBNAIL,
            12 => self::WALLPAPER,
            13 => self::VIDEO_NOTE,
            14 => self::SECURE_RAW,
            15 => self::SECURE,
            16 => self::BACKGROUND,
            17 => self::SIZE,
        };
    }

    /**
     * Obtain a bot API type ID.
     *
     * @internal Should not be used manually.
     */
    public function toInnerID(): int
    {
        return match ($this) {
            self::THUMBNAIL => 0,
            self::PROFILE_PHOTO => 1,
            self::PHOTO => 2,
            self::VOICE=> 3,
            self::VIDEO => 4,
            self::DOCUMENT=> 5,
            self::ENCRYPTED => 6,
            self::TEMP => 7,
            self::STICKER => 8,
            self::AUDIO => 9,
            self::ANIMATION => 10,
            self::ENCRYPTED_THUMBNAIL => 11,
            self::WALLPAPER => 12,
            self::VIDEO_NOTE => 13,
            self::SECURE_RAW => 14,
            self::SECURE => 15,
            self::BACKGROUND=> 16,
            self::SIZE=>17,
        };
    }

    /**
     * Convert file ID type to unique file ID type.
     */
    public function toUnique(): UniqueFileIdType
    {
        return match ($this) {
            self::PHOTO => UniqueFileIdType::PHOTO,
            self::PROFILE_PHOTO => UniqueFileIdType::PHOTO,
            self::THUMBNAIL => UniqueFileIdType::PHOTO,
            self::ENCRYPTED_THUMBNAIL => UniqueFileIdType::PHOTO,
            self::WALLPAPER => UniqueFileIdType::PHOTO,

            self::VIDEO => UniqueFileIdType::DOCUMENT,
            self::VOICE => UniqueFileIdType::DOCUMENT,
            self::DOCUMENT => UniqueFileIdType::DOCUMENT,
            self::STICKER => UniqueFileIdType::DOCUMENT,
            self::AUDIO => UniqueFileIdType::DOCUMENT,
            self::ANIMATION => UniqueFileIdType::DOCUMENT,
            self::VIDEO_NOTE => UniqueFileIdType::DOCUMENT,
            self::BACKGROUND => UniqueFileIdType::DOCUMENT,

            self::SECURE => UniqueFileIdType::SECURE,
            self::SECURE_RAW => UniqueFileIdType::SECURE,

            self::ENCRYPTED => UniqueFileIdType::ENCRYPTED,

            self::TEMP => UniqueFileIdType::TEMP,

            default => throw new AssertionError("Cannot convert file ID of type ".$this->name." to a unique file ID!")
        };
    }
}
