<?php declare(strict_types=1);

namespace Amp\Http\Client\Cookie\Internal;

use Amp\Dns\InvalidNameException;
use PHPUnit\Framework\TestCase;

class PublicSuffixListTest extends TestCase
{
    /**
     * @dataProvider provideTestData
     * @requires extension intl
     *
     * @throws InvalidNameException
     */
    public function testWithData($domain, $expectation): void
    {
        $this->assertSame($expectation, PublicSuffixList::isPublicSuffix($domain));
    }

    public function provideTestData(): array
    {
        $lines = \file(__DIR__ . '/../fixture/public_suffix_list_tests.txt', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        $lines = \array_filter($lines, static function ($line) {
            return !\str_starts_with($line, '//');
        });

        return \array_map(static function ($line) {
            $parts = \explode(' ', $line);

            return [
                $parts[0],
                (bool) $parts[1],
            ];
        }, $lines);
    }
}
