<?php

namespace danog\LibDNSJson\Test;

use danog\LibDNSJson\JsonDecoderFactory;
use danog\LibDNSJson\QueryEncoderFactory;
use LibDNS\Messages\MessageTypes;
use PHPUnit\Framework\TestCase;

class QueryEncoderTest extends TestCase
{
    /**
     * Test encoding of valid DNS message payloads.
     *
     * @return void
     *
     * @dataProvider provideValidQueryPayloads
     */
    public function testEncodesValidQueryPayloads(string $message)
    {
        $decoder = (new JsonDecoderFactory)->create();
        $response = $decoder->decode($message, 0);
        $response->setType(MessageTypes::QUERY);

        $encoder = (new QueryEncoderFactory)->create();
        $request = $encoder->encode($response);

        $this->assertIsString($request, "Got a ".\gettype($request)." instead of a string");
        \parse_str($request, $output);
        $this->assertNotEmpty($output);
        $this->assertArrayHasKey('cd', $output);
        $this->assertArrayHasKey('do', $output);
        $this->assertArrayHasKey('ct', $output);
        $this->assertArrayHasKey('type', $output);
        $this->assertArrayHasKey('name', $output);
        $this->assertEquals($output['cd'], 0);
        $this->assertEquals($output['do'], 0);
        $this->assertEquals($output['ct'], 'application/dns-json');
        $this->assertEquals($output['type'], $response->getQuestionRecords()->getRecordByIndex(0)->getType());
        $this->assertEquals($output['name'], \implode('.', $response->getQuestionRecords()->getRecordByIndex(0)->getName()->getLabels()));
    }

    public function provideValidQueryPayloads()
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
                2
            ],
            [
                '{"Status": 0,"TC": false,"RD": true, "RA": true, "AD": true,"CD": false,"Question":[{"name": "example.com.", "type": 28}],"Answer":[{"name": "example.com.", "type": 28, "TTL": 7092, "data": "2606:2800:220:1:248:1893:25c8:1946"}]}',
                3
            ],
            [
                '{"Status": 0,"TC": false,"RD": true, "RA": true, "AD": false,"CD": false,"Question":[{"name": "daniil.it.", "type": 1}],"Answer":[{"name": "daniil.it.", "type": 1, "TTL": 300, "data": "104.27.146.166"},{"name": "daniil.it.", "type": 1, "TTL": 300, "data": "104.27.147.166"}]}',
                3
            ],
            [
                '{"Status": 0,"TC": false,"RD": true, "RA": true, "AD": false,"CD": false,"Question":[{"name": "amphp.org.", "type": 15}],"Answer":[{"name": "amphp.org.", "type": 15, "TTL": 86400, "data": "0 mail.negativeion.net."}]}',
                3
            ],
        ];
    }

    /**
     * Test query encoding of invalid DNS payloads.
     *
     * @return void
     *
     * @dataProvider provideInvalidQueryPayloads
     */
    public function testEncodesInvalidQueryPayloads($request)
    {
        $encoder = (new QueryEncoderFactory)->create();
        $this->expectException(\InvalidArgumentException::class);
        $encoder->encode($request);
    }

    public function provideInvalidQueryPayloads()
    {
        $decoder = (new JsonDecoderFactory)->create();
        return [
            [
                $decoder->decode(
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
                    2
                )
            ],
            [
                $decoder->decode(
                    '{
                        "Status": 0,
                        "TC": false,
                        "RD": true,
                        "RA": true,
                        "AD": false,
                        "CD": false,
                        "Question":
                        [
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
                    2
                )
            ],
        ];
    }
}
