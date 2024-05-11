<?php declare(strict_types=1);
/**
 * Creates QueryEncoder objects.
 *
 * PHP version 5.4
 *
 * @category LibDNS
 * @package Encoder
 * @author Daniil Gentili <https://daniil.it>, Chris Wright <https://github.com/DaveRandom>
 * @copyright Copyright (c) Chris Wright <https://github.com/DaveRandom>
 * @license http://www.opensource.org/licenses/mit-license.html MIT License
 * @version 2.0.0
 */

namespace danog\LibDNSJson;

/**
 * Creates QueryEncoder objects.
 *
 * @category LibDNS
 * @package Encoder
 * @author Daniil Gentili <https://daniil.it>, Chris Wright <https://github.com/DaveRandom>
 */
class QueryEncoderFactory
{
    /**
     * Create a new Encoder object.
     *
     */
    public function create(): QueryEncoder
    {
        return new QueryEncoder();
    }
}
