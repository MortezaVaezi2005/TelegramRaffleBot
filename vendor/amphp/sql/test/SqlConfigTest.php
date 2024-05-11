<?php declare(strict_types=1);

namespace Amp\Sql\Test;

use Amp\Sql\SqlConfig;
use PHPUnit\Framework\TestCase;

class SqlConfigTest extends TestCase
{
    private function createConfigFromString(string $connectionString): SqlConfig
    {
        return new class($connectionString) extends SqlConfig {
            public function __construct(string $connectionString)
            {
                $parts = self::parseConnectionString($connectionString);

                parent::__construct(
                    host: $parts["host"] ?? '',
                    port: (int) ($parts["port"] ?? 0),
                    user: $parts["user"] ?? "",
                    password: $parts["password"] ?? "",
                    database: $parts["db"] ?? "",
                );
            }
        };
    }

    public function testPortInHost(): void
    {
        $config = $this->createConfigFromString("host=localhost:5432 user=user database=test");

        self::assertSame("localhost", $config->getHost());
        self::assertSame(5432, $config->getPort());
        self::assertSame("user", $config->getUser());
        self::assertSame("", $config->getPassword());
        self::assertSame("test", $config->getDatabase());
    }

    public function testBasicSyntax(): void
    {
        $config = $this->createConfigFromString("host=localhost port=5432 user=user pass=test db=test");

        self::assertSame("localhost", $config->getHost());
        self::assertSame(5432, $config->getPort());
        self::assertSame("user", $config->getUser());
        self::assertSame("test", $config->getPassword());
        self::assertSame("test", $config->getDatabase());
    }

    public function testAlternativeSyntax(): void
    {
        $config = $this->createConfigFromString("host=localhost;port=3306;user=user;password=test;db=test");

        self::assertSame("localhost", $config->getHost());
        self::assertSame(3306, $config->getPort());
        self::assertSame("user", $config->getUser());
        self::assertSame("test", $config->getPassword());
        self::assertSame("test", $config->getDatabase());
    }

    public function testInvalidString(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage("Empty key name in connection string");
        $this->createConfigFromString("invalid =connection string");
    }
}
