<?php declare(strict_types = 1);
/**
 * Encodes Message objects to query strings.
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

use \LibDNS\Messages\Message;
use LibDNS\Messages\MessageTypes;

/**
 * Encodes Message objects to query strings.
 *
 * @category LibDNS
 * @package Encoder
 * @author Daniil Gentili <https://daniil.it>, Chris Wright <https://github.com/DaveRandom>
 */
class QueryEncoder
{
    /**
     * Encode a Message to URL payload.
     *
     * @param \LibDNS\Messages\Message $message  The Message to encode
     */
    public function encode(Message $message): string
    {
        if ($message->getType() !== MessageTypes::QUERY) {
            throw new \InvalidArgumentException('Invalid question: is not a question record');
        }
        $questions = $message->getQuestionRecords();
        if ($questions->count() === 0) {
            throw new \InvalidArgumentException('Invalid question: 0 question records provided');
        }
        $question = $questions->getRecordByIndex(0);
        return \http_build_query(
            [
                'cd' => 0, // Do not disable result validation
                'do' => 0, // Do not send me DNSSEC data
                'type' => $question->getType(), // Record type being requested
                'name' => \implode('.', $question->getName()->getLabels()), // Record name being requested
                'ct' => 'application/dns-json', // Content-type of request
            ]
        );
    }
}
