<?php declare(strict_types=1);

namespace Amp\Redis;

use League\Uri\Uri;

final class RedisConfig
{
    public const DEFAULT_HOST = 'localhost';
    public const DEFAULT_PORT = 6379;
    public const DEFAULT_TIMEOUT = 5;

    /**
     * @throws RedisException
     */
    public static function fromUri(string $uri, float $timeout = self::DEFAULT_TIMEOUT): self
    {
        return new self($uri, $timeout);
    }

    private string $uri;
    private string $password;
    private int $database;
    private float $timeout;

    /**
     * @throws RedisException
     */
    private function __construct(string $uri, float $timeout)
    {
        $this->applyUri($uri);
        $this->timeout = $timeout;
    }

    public function getConnectUri(): string
    {
        return $this->uri;
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function hasPassword(): bool
    {
        return $this->password !== '';
    }

    public function getDatabase(): int
    {
        return $this->database;
    }

    public function withTimeout(float $timeout): self
    {
        $clone = clone $this;
        $clone->timeout = $timeout;

        return $clone;
    }

    public function withPassword(string $password): self
    {
        $clone = clone $this;
        $clone->password = $password;

        return $clone;
    }

    public function withDatabase(int $database): self
    {
        $clone = clone $this;
        $clone->database = $database;

        return $clone;
    }

    /**
     * When using the "redis" schemes the URI is parsed according to the rules defined by the provisional registration
     * documents approved by IANA. If the URI has a password in its "user-information" part or a database number in the
     * "path" part these values override the values of "password" / "database" if they are present in the "query" part.
     *
     * @link http://www.iana.org/assignments/uri-schemes/prov/redis
     *
     * @param string $uri URI string.
     *
     * @throws RedisException
     */
    private function applyUri(string $uri): void
    {
        try {
            // Upgrade to new API before supporting league/uri 8.x
            /** @psalm-suppress DeprecatedMethod */
            $uri = Uri::createFromString($uri);
        } catch (\Exception) {
            throw new RedisException('Invalid redis configuration URI: ' . $uri);
        }

        $scheme = match (\strtolower($uri->getScheme() ?? '')) {
            'tcp', 'redis' => 'tcp',
            'unix' => 'unix',
            default => throw new RedisException(
                'Invalid scheme for redis URI, must be tcp, unix, or redis, got ' . $uri->getScheme()
            ),
        };

        \parse_str($uri->getQuery() ?? '', $query);

        [, $password] = \explode(':', $uri->getUserInfo() ?? '', 2) + [null, null];
        $this->password = $password ?? $query['password'] ?? $query['pass'] ?? '';

        $this->database = (int) ($query['database'] ?? $query['db'] ?? 0);

        if ($scheme === 'unix') {
            $this->uri = 'unix://' . $uri->getPath();
            return;
        }

        $path = \ltrim($uri->getPath(), '/');
        if ($path !== '') {
            $this->database = (int) $path;
        }

        $this->uri = \sprintf(
            'tcp://%s:%d',
            $uri->getHost() ?: self::DEFAULT_HOST,
            $uri->getPort() ?: self::DEFAULT_PORT,
        );
    }
}
