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

/**
 * @internal Not for public use
 */
enum PhotoSizeSourceType: int
{
    case LEGACY = 0;
    case THUMBNAIL = 1;
    case DIALOGPHOTO_SMALL = 2;
    case DIALOGPHOTO_BIG = 3;
    case STICKERSET_THUMBNAIL = 4;
    case FULL_LEGACY = 5;
    case DIALOGPHOTO_SMALL_LEGACY = 6;
    case DIALOGPHOTO_BIG_LEGACY = 7;
    case STICKERSET_THUMBNAIL_LEGACY = 8;
    case STICKERSET_THUMBNAIL_VERSION = 9;
}
