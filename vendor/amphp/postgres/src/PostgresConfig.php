<?php declare(strict_types=1);

namespace Amp\Postgres;

use Amp\Sql\SqlConfig;

final class PostgresConfig extends SqlConfig
{
    public const DEFAULT_PORT = 5432;

    public const SSL_MODES = [
        'disable',
        'allow',
        'prefer',
        'require',
        'verify-ca',
        'verify-full',
    ];

    public const KEY_MAP = [
        ...parent::KEY_MAP,
        'ssl_mode' => 'sslmode',
        'sslMode' => 'sslmode',
        'applicationName' => 'application_name',
    ];

    private ?string $connectionString = null;

    public static function fromString(string $connectionString): self
    {
        $parts = self::parseConnectionString($connectionString, self::KEY_MAP);

        if (!isset($parts["host"])) {
            throw new \Error("Host must be provided in connection string");
        }

        return new self(
            $parts["host"],
            (int) ($parts["port"] ?? self::DEFAULT_PORT),
            $parts["user"] ?? null,
            $parts["password"] ?? null,
            $parts["db"] ?? null,
            $parts["application_name"] ?? null,
            $parts["sslmode"] ?? null,
        );
    }

    public function __construct(
        string $host,
        int $port = self::DEFAULT_PORT,
        ?string $user = null,
        ?string $password = null,
        ?string $database = null,
        private ?string $applicationName = null,
        private ?string $sslMode = null,
    ) {
        self::assertValidSslMode($sslMode);

        parent::__construct($host, $port, $user, $password, $database);
    }

    public function __clone()
    {
        $this->connectionString = null;
    }

    public function getSslMode(): ?string
    {
        return $this->sslMode;
    }

    private static function assertValidSslMode(?string $mode): void
    {
        if ($mode === null) {
            return;
        }

        if (!\in_array($mode, self::SSL_MODES, true)) {
            throw new \Error('Invalid SSL mode, must be one of: ' . \implode(', ', self::SSL_MODES));
        }
    }

    public function withSslMode(string $mode): self
    {
        self::assertValidSslMode($mode);

        $new = clone $this;
        $new->sslMode = $mode;
        return $new;
    }

    public function withoutSslMode(): self
    {
        $new = clone $this;
        $new->sslMode = null;
        return $new;
    }

    public function getApplicationName(): ?string
    {
        return $this->applicationName;
    }

    public function withApplicationName(string $name): self
    {
        $new = clone $this;
        $new->applicationName = $name;
        return $new;
    }

    public function withoutApplicationName(): self
    {
        $new = clone $this;
        $new->applicationName = null;
        return $new;
    }

    /**
     * @return string Connection string used with ext-pgsql and pecl-pq.
     */
    public function getConnectionString(): string
    {
        if ($this->connectionString !== null) {
            return $this->connectionString;
        }

        $chunks = [
            "host=" . $this->getHost(),
            "port=" . $this->getPort(),
        ];

        $user = $this->getUser();
        if ($user !== null) {
            $chunks[] = "user=" . $user;
        }

        $password = $this->getPassword();
        if ($password !== null) {
            $chunks[] = "password=" . $password;
        }

        $database = $this->getDatabase();
        if ($database !== null) {
            $chunks[] = "dbname=" . $database;
        }

        if ($this->sslMode !== null) {
            $chunks[] = "sslmode=" . $this->sslMode;
        }

        if ($this->applicationName !== null) {
            $chunks[] = \sprintf("application_name='%s'", \addslashes($this->applicationName));
        }

        return $this->connectionString = \implode(" ", $chunks);
    }
}
