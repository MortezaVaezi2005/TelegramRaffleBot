<?php declare(strict_types=1);

namespace Amp\Redis\Command\Option;

final class SetOptions
{
    private ?int $ttl = null;
    private string $ttlUnit = 'EX';
    private ?string $existenceFlag = null;

    public function withTtl(int $seconds): self
    {
        $clone = clone $this;
        $clone->ttl = $seconds;
        $clone->ttlUnit = 'EX';

        return $clone;
    }

    public function withTtlInMillis(int $millis): self
    {
        $clone = clone $this;
        $clone->ttl = $millis;
        $clone->ttlUnit = 'PX';

        return $clone;
    }

    public function withoutOverwrite(): self
    {
        $clone = clone $this;
        $clone->existenceFlag = 'NX';

        return $clone;
    }

    public function withoutCreation(): self
    {
        $clone = clone $this;
        $clone->existenceFlag = 'XX';

        return $clone;
    }

    /**
     * @return list<int|string>
     */
    public function toQuery(): array
    {
        $query = [];

        if ($this->ttl !== null) {
            $query[] = $this->ttlUnit;
            $query[] = $this->ttl;
        }

        if ($this->existenceFlag !== null) {
            $query[] = $this->existenceFlag;
        }

        return $query;
    }
}
