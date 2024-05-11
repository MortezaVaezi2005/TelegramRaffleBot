<?php

namespace danog\LibDNSJson\Test;

use danog\LibDNSJson\JsonDecoder;
use danog\LibDNSJson\JsonDecoderFactory;
use PHPUnit\Framework\TestCase;

class JsonDecoderFactoryTest extends TestCase
{
    public function testJsonDecoderFactoryWorks()
    {
        $this->assertInstanceOf(JsonDecoder::class, (new JsonDecoderFactory)->create());
    }
}
