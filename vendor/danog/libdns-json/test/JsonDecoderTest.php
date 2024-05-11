<?php

namespace danog\LibDNSJson\Test;

use danog\LibDNSJson\JsonDecoderFactory;
use LibDNS\Messages\Message;
use LibDNS\Messages\MessageTypes;
use PHPUnit\Framework\TestCase;

class JsonDecoderTest extends TestCase
{
    /**
     * Test decoding of valid JSON DNS payloads.
     *
     * @return void
     *
     * @dataProvider provideValidJsonPayloads
     */
    public function testDecodesValidJsonPayloads(string $message, int $requestId)
    {
        $decoder = (new JsonDecoderFactory)->create();
        $response = $decoder->decode($message, $requestId);

        $this->assertInstanceOf(Message::class, $response);
        $this->assertEquals(MessageTypes::RESPONSE, $response->getType());
    }

    public function provideValidJsonPayloads()
    {
        return [
            [
                '{
                    "Status": 0,
                    "TC": false,
                    "RD": true,
                    "RA": true,
                    "AD": false,
                    "CD": false,
                    "Question":
                    [
                    {
                        "name": "apple.com.",
                        "type": 1
                    }
                    ],
                    "Answer":
                    [
                    {
                        "name": "apple.com.",
                        "type": 1,
                        "TTL": 3599,
                        "data": "17.178.96.59"
                    },
                    {
                        "name": "apple.com.",
                        "type": 1,
                        "TTL": 3599,
                        "data": "17.172.224.47"
                    },
                    {
                        "name": "apple.com.",
                        "type": 1,
                        "TTL": 3599,
                        "data": "17.142.160.59"
                    }
                    ],
                    "Additional": [ ],
                    "edns_client_subnet": "12.34.56.78/0"
                }',
                2,
            ],
            [
                '{"Status": 0,"TC": false,"RD": true, "RA": true, "AD": true,"CD": false,"Question":[{"name": "example.com.", "type": 28}],"Answer":[{"name": "example.com.", "type": 28, "TTL": 7092, "data": "2606:2800:220:1:248:1893:25c8:1946"}]}',
                3,
            ],
            [
                '{"Status": 0,"TC": false,"RD": true, "RA": true, "AD": false,"CD": false,"Question":[{"name": "daniil.it.", "type": 1}],"Answer":[{"name": "daniil.it.", "type": 1, "TTL": 300, "data": "104.27.146.166"},{"name": "daniil.it.", "type": 1, "TTL": 300, "data": "104.27.147.166"}]}',
                3,
            ],
            [
                '{"Status": 0,"TC": false,"RD": true, "RA": true, "AD": false,"CD": false,"Question":[{"name": "amphp.org.", "type": 15}],"Answer":[{"name": "amphp.org.", "type": 15, "TTL": 86400, "data": "0 mail.negativeion.net."}]}',
                3,
            ],
            [
                '{"Status": 0,"TC": false,"RD": true,"RA": true,"AD": false,"CD": false,"Question":[ {"name": "daniil.it.","type": 6}],"Answer":[ {"name": "daniil.it.","type": 6,"TTL": 3493,"data": "cruz.ns.cloudflare.com. dns.cloudflare.com. 2031387933 10000 2400 604800 3600"}]}',
                3,
            ],
        ];
    }

    /**
     * Test decoding of invalid JSON DNS payloads.
     *
     * @param string $message
     * @param int $requestId
     * @return void
     *
     * @dataProvider provideInvalidJsonPayloads
     */
    public function testDecodesInvalidJsonPayloads($message, $requestId)
    {
        $decoder = (new JsonDecoderFactory)->create();
        $this->expectException(\InvalidArgumentException::class);
        $decoder->decode($message, $requestId);
    }

    public function provideInvalidJsonPayloads()
    {
        return [
            [
                '{lmfao
                    "Status": 0,
                    "TC": false,
                    "RD": true,
                    "RA": true,
                    "AD": false,
                    "CD": false,
                    "Question":
                    [
                    {
                        "name": "apple.com.",
                        "type": 1
                    }
                    ],
                    "Answer":
                    [
                    {
                        "name": "apple.com.",
                        "type": 1,
                        "TTL": 3599,
                        "data": "17.178.96.59"
                    },
                    {
                        "name": "apple.com.",
                        "type": 1,
                        "TTL": 3599,
                        "data": "17.172.224.47"
                    },
                    {
                        "name": "apple.com.",
                        "type": 1,
                        "TTL": 3599,
                        "data": "17.142.160.59"
                    }
                    ],
                    "Additional": [ ],
                    "edns_client_subnet": "12.34.56.78/0"
                }',
                2,
            ],
            [
                'xd{"Status": 0,"TC": false,"RD": true, "RA": true, "AD": true,"CD": false,"Question":[{"name": "example.com.", "type": 28}],"Answer":[{"name": "example.com.", "type": 28, "TTL": 7092, "data": "2606:2800:220:1:248:1893:25c8:1946"}]}',
                3,
            ],
            [
                'whaaa{"Status": 0,"TC": false,"RD": true, "RA": true, "AD": false,"CD": false,"Question":[{"name": "daniil.it.", "type": 1}],"Answer":[{"name": "daniil.it.", "type": 1, "TTL": 300, "data": "104.27.146.166"},{"name": "daniil.it.", "type": 1, "TTL": 300, "data": "104.27.147.166"}]}',
                3,
            ],
            [
                'xdxdxxxxx{"Status": 0,"TC": false,"RD": true, "RA": true, "AD": false,"CD": false,"Question":[{"name": "amphp.org.", "type": 15}],"Answer":[{"name": "amphp.org.", "type": 15, "TTL": 86400, "data": "0 mail.negativeion.net."}]}',
                3,
            ],
            [
                '{"TC": false,"RD": true, "RA": true, "AD": false,"CD": false,"Question":[{"name": "amphp.org.", "type": 15}],"Answer":[{"name": "amphp.org.", "type": 15, "TTL": 86400, "data": "0 mail.negativeion.net."}]}',
                3,
            ],
            [
                '{"Status": 0,"RD": true, "RA": true, "AD": false,"CD": false,"Question":[{"name": "amphp.org.", "type": 15}],"Answer":[{"name": "amphp.org.", "type": 15, "TTL": 86400, "data": "0 mail.negativeion.net."}]}',
                3,
            ],
            [
                '{"Status": 0,"TC": false,"RA": true, "AD": false,"CD": false,"Question":[{"name": "amphp.org.", "type": 15}],"Answer":[{"name": "amphp.org.", "type": 15, "TTL": 86400, "data": "0 mail.negativeion.net."}]}',
                3,
            ],
            [
                '{"Status": 0,"TC": false,"RD": true, "AD": false,"CD": false,"Question":[{"name": "amphp.org.", "type": 15}],"Answer":[{"name": "amphp.org.", "type": 15, "TTL": 86400, "data": "0 mail.negativeion.net."}]}',
                3,
            ],
            [
                'xd',
                0
            ],
        ];
    }
}
