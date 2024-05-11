<?php declare(strict_types=1);

namespace Amp\Redis\Command\Option;

final class SortOptions
{
    private ?string $pattern = null;
    private ?int $offset = null;
    private ?int $count = null;
    private bool $ascending = true;
    private bool $lexicographically = false;

    public function hasPattern(): bool
    {
        return $this->pattern !== null;
    }

    public function getPattern(): ?string
    {
        return $this->pattern;
    }

    public function withPattern(string $pattern): self
    {
        $clone = clone $this;
        $clone->pattern = $pattern;

        return $clone;
    }

    public function withoutPattern(): self
    {
        $clone = clone $this;
        $clone->pattern = null;

        return $clone;
    }

    public function getOffset(): ?int
    {
        return $this->offset;
    }

    public function getCount(): ?int
    {
        return $this->count;
    }

    public function hasLimit(): bool
    {
        return $this->offset !== null;
    }

    public function withLimit(int $offset, int $count): self
    {
        $clone = clone $this;
        $clone->offset = $offset;
        $clone->count = $count;

        return $clone;
    }

    public function withoutLimit(): self
    {
        $clone = clone $this;
        $clone->offset = null;
        $clone->count = null;

        return $clone;
    }

    public function isAscending(): bool
    {
        return $this->ascending;
    }

    public function isDescending(): bool
    {
        return !$this->ascending;
    }

    public function withAscendingOrder(): self
    {
        $clone = clone $this;
        $clone->ascending = true;

        return $clone;
    }

    public function withDescendingOrder(): self
    {
        $clone = clone $this;
        $clone->ascending = false;

        return $clone;
    }

    public function isLexicographicSorting(): bool
    {
        return $this->lexicographically;
    }

    public function withLexicographicSorting(): self
    {
        $clone = clone $this;
        $clone->lexicographically = true;

        return $clone;
    }

    public function withNumericSorting(): self
    {
        $clone = clone $this;
        $clone->lexicographically = false;

        return $clone;
    }

    /**
     * @return list<int|string>
     */
    public function toQuery(): array
    {
        $payload = [];

        $pattern = $this->getPattern();
        if ($pattern !== null) {
            $payload[] = 'BY';
            $payload[] = $pattern;
        }

        if ($this->hasLimit()) {
            $offset = $this->getOffset();
            $count = $this->getCount();

            \assert($offset !== null);
            \assert($count !== null);

            $payload[] = 'LIMIT';
            $payload[] = $offset;
            $payload[] = $count;
        }

        if ($this->isDescending()) {
            $payload[] = 'DESC';
        }

        if ($this->isLexicographicSorting()) {
            $payload[] = 'ALPHA';
        }

        return $payload;
    }
}
