<?php

namespace danog\LibDNSJson\Test;

use danog\LibDNSJson\QueryEncoder;
use danog\LibDNSJson\QueryEncoderFactory;
use PHPUnit\Framework\TestCase;

class QueryEncoderFactoryTest extends TestCase
{
    public function testQueryEncoderFactoryWorks()
    {
        $this->assertInstanceOf(QueryEncoder::class, (new QueryEncoderFactory)->create());
    }
}
