<?php declare(strict_types=1);

namespace Amp\Mysql;

use Amp\Socket\ConnectContext;
use Amp\Sql\SqlConfig;

final class MysqlConfig extends SqlConfig
{
    public const DEFAULT_PORT = 3306;
    public const BIN_CHARSET = 255; // utf8mb4_0900_ai_ci

    public const KEY_MAP = [
        ...parent::KEY_MAP,
        'compress' => 'compression',
        'useCompression' => 'compression',
        'cs' => 'charset',
        'localInfile' => 'local-infile',
    ];

    public const DEFAULT_CHARSET = "utf8mb4";
    public const DEFAULT_COLLATE = "utf8mb4_0900_ai_ci";

    private ConnectContext $context;

    public static function fromString(string $connectionString, ?ConnectContext $context = null): self
    {
        $parts = self::parseConnectionString($connectionString, self::KEY_MAP);

        if (!isset($parts['host'])) {
            throw new \Error('Host must be provided in connection string');
        }

        return new self(
            host: $parts['host'],
            port: (int) ($parts['port'] ?? self::DEFAULT_PORT),
            user: $parts['user'] ?? null,
            password: $parts['password'] ?? null,
            database: $parts['db'] ?? null,
            context: $context,
            charset: $parts['charset'] ?? self::DEFAULT_CHARSET,
            collate: $parts['collate'] ?? self::DEFAULT_COLLATE,
            useCompression: ($parts['compression'] ?? '') === 'on',
            useLocalInfile: ($parts['local-infile'] ?? '') === 'on'
        );
    }

    public static function fromAuthority(
        string $authority,
        string $user,
        string $password,
        ?string $database = null,
        ?ConnectContext $context = null,
    ): self {
        [$host, $port] = \explode(':', $authority, 2) + ['', (string) self::DEFAULT_PORT];

        return new self(
            host: $host,
            port: (int) $port,
            user: $user,
            password: $password,
            database: $database,
            context: $context,
        );
    }

    /**
     * @param string $key Private key to use for sha256_password auth method
     */
    public function __construct(
        string $host,
        int $port = self::DEFAULT_PORT,
        ?string $user = null,
        ?string $password = null,
        ?string $database = null,
        ?ConnectContext $context = null,
        private string $charset = self::DEFAULT_CHARSET,
        private string $collate = self::DEFAULT_COLLATE,
        private ?string $sqlMode = null,
        private bool $useCompression = false,
        private string $key = '',
        private bool $useLocalInfile = false,
    ) {
        parent::__construct($host, $port, $user, $password, $database);

        $this->context = $context ?? new ConnectContext();
    }

    public function getConnectionString(): string
    {
        return $this->getHost()[0] == "/"
            ? 'unix://' . $this->getHost()
            : 'tcp://' . $this->getHost() . ':' . $this->getPort();
    }

    public function isCompressionEnabled(): bool
    {
        return $this->useCompression;
    }

    public function withCompression(): self
    {
        $new = clone $this;
        $new->useCompression = true;
        return $new;
    }

    public function withoutCompression(): self
    {
        $new = clone $this;
        $new->useCompression = false;
        return $new;
    }

    public function isLocalInfileEnabled(): bool
    {
        return $this->useLocalInfile;
    }

    public function withLocalInfile(): self
    {
        $new = clone $this;
        $new->useLocalInfile = true;
        return $new;
    }

    public function withoutLocalInfile(): self
    {
        $new = clone $this;
        $new->useLocalInfile = false;
        return $new;
    }

    public function getConnectContext(): ConnectContext
    {
        return $this->context;
    }

    public function withConnectContext(ConnectContext $context): self
    {
        $new = clone $this;
        $new->context = $context;
        return $new;
    }

    public function getCharset(): string
    {
        return $this->charset;
    }

    public function getCollation(): string
    {
        return $this->collate;
    }

    public function withCharset(string $charset, string $collate): self
    {
        $new = clone $this;
        $new->charset = $charset;
        $new->collate = $collate;
        return $new;
    }

    public function getSqlMode(): ?string
    {
        return $this->sqlMode;
    }

    /**
     * Set the Server SQL Modes at connection time.
     * @see https://dev.mysql.com/doc/refman/8.0/en/sql-mode.html
     */
    public function withSqlMode(?string $sqlMode): self
    {
        $new = clone $this;
        $new->sqlMode = $sqlMode;
        return $new;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function withKey(string $key): self
    {
        $new = clone $this;
        $new->key = $key;
        return $new;
    }
}
