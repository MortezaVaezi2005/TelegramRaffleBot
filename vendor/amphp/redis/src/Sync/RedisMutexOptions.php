<?php declare(strict_types=1);

namespace Amp\Redis\Sync;

final class RedisMutexOptions
{
    private string $keyPrefix = '';
    private float $lockRenewInterval = 1;
    private float $lockExpiration = 3;
    private float $lockTimeout = 10;

    public function getKeyPrefix(): string
    {
        return $this->keyPrefix;
    }

    public function getLockExpiration(): float
    {
        return $this->lockExpiration;
    }

    public function getLockRenewInterval(): float
    {
        return $this->lockRenewInterval;
    }

    public function getLockTimeout(): float
    {
        return $this->lockTimeout;
    }

    public function withKeyPrefix(string $keyPrefix): self
    {
        $clone = clone $this;
        $clone->keyPrefix = $keyPrefix;

        return $clone;
    }

    public function withLockExpiration(float $lockExpiration): self
    {
        $clone = clone $this;
        $clone->lockExpiration = $lockExpiration;

        return $clone;
    }

    public function withLockRenewInterval(float $lockRenewInterval): self
    {
        $clone = clone $this;
        $clone->lockRenewInterval = $lockRenewInterval;

        return $clone;
    }

    public function withLockTimeout(float $lockTimeout): self
    {
        $clone = clone $this;
        $clone->lockTimeout = $lockTimeout;

        return $clone;
    }
}
