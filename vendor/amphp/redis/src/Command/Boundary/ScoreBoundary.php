<?php declare(strict_types=1);

namespace Amp\Redis\Command\Boundary;

final class ScoreBoundary
{
    public static function exclusive(float $value): self
    {
        return new self('(' . $value);
    }

    public static function inclusive(float $value): self
    {
        return new self((string) $value);
    }

    public static function negativeInfinity(): self
    {
        return new self('-inf');
    }

    public static function positiveInfinity(): self
    {
        return new self('+inf');
    }

    private function __construct(private readonly string $value)
    {
    }

    public function toQuery(): string
    {
        return $this->value;
    }
}
