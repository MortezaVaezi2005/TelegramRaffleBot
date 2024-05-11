<?php declare(strict_types=1);

namespace Amp\Redis\Command\Boundary;

final class LexBoundary
{
    /**
     * @param non-empty-string $value
     */
    public static function exclusive(string $value): self
    {
        return new self('(' . $value);
    }

    /**
     * @param non-empty-string $value
     */
    public static function inclusive(string $value): self
    {
        return new self('[' . $value);
    }

    public static function negativeInfinity(): self
    {
        return new self('-');
    }

    public static function positiveInfinity(): self
    {
        return new self('+');
    }

    private function __construct(private readonly string $value)
    {
    }

    public function toQuery(): string
    {
        return $this->value;
    }
}
