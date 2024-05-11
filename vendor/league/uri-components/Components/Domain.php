<?php

/**
 * League.Uri (https://uri.thephpleague.com)
 *
 * (c) Ignace Nyamagana Butera <nyamsprod@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace League\Uri\Components;

use Iterator;
use League\Uri\Contracts\AuthorityInterface;
use League\Uri\Contracts\DomainHostInterface;
use League\Uri\Contracts\HostInterface;
use League\Uri\Contracts\UriComponentInterface;
use League\Uri\Contracts\UriInterface;
use League\Uri\Exceptions\OffsetOutOfBounds;
use League\Uri\Exceptions\SyntaxError;
use Psr\Http\Message\UriInterface as Psr7UriInterface;
use Stringable;
use TypeError;

use function array_count_values;
use function array_filter;
use function array_keys;
use function array_reverse;
use function array_shift;
use function count;
use function explode;
use function implode;
use function sprintf;

final class Domain extends Component implements DomainHostInterface
{
    private const SEPARATOR = '.';

    private readonly HostInterface $host;
    /** @var string[] */
    private readonly array $labels;

    private function __construct(Stringable|string|null $host)
    {
        $host = match (true) {
            $host instanceof HostInterface => $host,
            $host instanceof UriComponentInterface => Host::new($host->value()),
            default => Host::new($host),
        };

        if (!$host->isDomain()) {
            throw new SyntaxError(sprintf('`%s` is an invalid domain name.', $host->value() ?? 'null'));
        }

        $this->host = $host;
        $this->labels = array_reverse(explode(self::SEPARATOR, $this->host->value() ?? ''));
    }

    /**
     * Returns a new instance from a string or a stringable object.
     */
    public static function new(Stringable|string|null $value = null): self
    {
        return new self($value);
    }

    /**
     * Returns a new instance from an iterable structure.
     */
    public static function fromLabels(Stringable|string ...$labels): self
    {
        return new self(match ([]) {
            $labels => null,
            default => implode(self::SEPARATOR, array_reverse(array_map(
                fn ($label) => self::filterComponent($label),
                $labels
            ))),
        });
    }

    /**
     * Create a new instance from a URI object.
     */
    public static function fromUri(Stringable|string $uri): self
    {
        return self::new(Host::fromUri($uri));
    }

    /**
     * Create a new instance from an Authority object.
     */
    public static function fromAuthority(Stringable|string $authority): self
    {
        return self::new(Host::fromAuthority($authority));
    }

    public function value(): ?string
    {
        return $this->host->value();
    }

    public function toAscii(): ?string
    {
        return $this->host->toAscii();
    }

    public function toUnicode(): ?string
    {
        return $this->host->toUnicode();
    }

    public function isIp(): bool
    {
        return false;
    }

    public function isDomain(): bool
    {
        return true;
    }

    public function isRegisteredName(): bool
    {
        return true;
    }

    public function getIpVersion(): ?string
    {
        return null;
    }

    public function getIp(): ?string
    {
        return null;
    }

    public function count(): int
    {
        return count($this->labels);
    }

    public function getIterator(): Iterator
    {
        yield from $this->labels;
    }

    public function get(int $offset): ?string
    {
        if ($offset < 0) {
            $offset += count($this->labels);
        }

        return $this->labels[$offset] ?? null;
    }

    public function keys(?string $label = null): array
    {
        return match (null) {
            $label => array_keys($this->labels),
            default => array_keys($this->labels, $label, true),
        };
    }

    public function isAbsolute(): bool
    {
        return count($this->labels) > 1 && '' === $this->labels[array_key_first($this->labels)];
    }

    public function prepend(Stringable|string|int|null $label): DomainHostInterface
    {
        $label = self::filterComponent($label);
        $value = $this->value();

        return match (true) {
            null === $label => $this,
            null === $value => new self($label),
            str_ends_with($label, self::SEPARATOR) => new self($label.$value),
            default => new self($label.self::SEPARATOR.$value),
        };
    }

    public function append(Stringable|string|int|null $label): DomainHostInterface
    {
        $label = self::filterComponent($label);
        $value = $this->value();

        return match (true) {
            null === $label => $this,
            null === $value => new self($label),
            !$this->isAbsolute() => new self($value.self::SEPARATOR.$label),
            str_ends_with($label, self::SEPARATOR) => new self($value.$label),
            default => new self($value.$label.self::SEPARATOR),
        };
    }

    public function withRootLabel(): DomainHostInterface
    {
        $key = array_key_first($this->labels);

        return match ($this->labels[$key]) {
            '' => $this,
            default => $this->append(''),
        };
    }

    public function slice(int $offset, ?int $length = null): self
    {
        $nbLabels = count($this->labels);
        if ($offset < -$nbLabels || $offset > $nbLabels) {
            throw new OffsetOutOfBounds(sprintf('No label can be found with at : `%s`.', $offset));
        }

        $labels = array_slice($this->labels, $offset, $length, true);

        return match ($labels) {
            $this->labels => $this,
            default => self::fromLabels(...$labels),
        };
    }

    public function withoutRootLabel(): DomainHostInterface
    {
        $key = array_key_first($this->labels);
        if ('' !== $this->labels[$key]) {
            return $this;
        }

        $labels = $this->labels;
        array_shift($labels);

        return self::fromLabels(...$labels);
    }

    /**
     * @throws OffsetOutOfBounds
     */
    public function withLabel(int $key, Stringable|string|int|null $label): DomainHostInterface
    {
        $nbLabels = count($this->labels);
        if ($key < - $nbLabels - 1 || $key > $nbLabels) {
            throw new OffsetOutOfBounds(sprintf('No label can be added with the submitted key : `%s`.', $key));
        }

        if (0 > $key) {
            $key += $nbLabels;
        }

        if ($nbLabels === $key) {
            return $this->append($label);
        }

        if (-1 === $key) {
            return $this->prepend($label);
        }

        if (!$label instanceof HostInterface && null !== $label) {
            $label = Host::new((string) $label)->value();
        }

        if ($label === $this->labels[$key]) {
            return $this;
        }

        $labels = $this->labels;
        $labels[$key] = $label;

        return new self(implode(self::SEPARATOR, array_reverse($labels)));
    }

    public function withoutLabel(int ...$keys): DomainHostInterface
    {
        if ([] === $keys) {
            return $this;
        }

        $nb_labels = count($this->labels);
        foreach ($keys as &$offset) {
            if (- $nb_labels > $offset || $nb_labels - 1 < $offset) {
                throw new OffsetOutOfBounds(sprintf('No label can be removed with the submitted key : `%s`.', $offset));
            }

            if (0 > $offset) {
                $offset += $nb_labels;
            }
        }
        unset($offset);

        $deleted_keys = array_keys(array_count_values($keys));
        $filter = static fn ($key): bool => !in_array($key, $deleted_keys, true);

        return self::fromLabels(...array_filter($this->labels, $filter, ARRAY_FILTER_USE_KEY));
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see Domain::getIterator()
     *
     * @codeCoverageIgnore
     *
     * Returns a new instance from a string or a stringable object.
     */
    public function labels(): array
    {
        return $this->labels;
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see Domain::new()
     *
     * @codeCoverageIgnore
     *
     * Returns a new instance from a string or a stringable object.
     */
    public static function createFromString(Stringable|string $host): self
    {
        return self::new($host);
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see Domain::fromLabels()
     *
     * @codeCoverageIgnore
     *
     * Returns a new instance from an iterable structure.
     *
     * @throws TypeError If a label is the null value
     */
    public static function createFromLabels(iterable $labels): self
    {
        return self::fromLabels(...$labels);
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see Domain::fromUri()
     *
     * @codeCoverageIgnore
     *
     * Create a new instance from a URI object.
     */
    public static function createFromUri(Psr7UriInterface|UriInterface $uri): self
    {
        return self::fromUri($uri);
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see Domain::fromAuthority()
     *
     * @codeCoverageIgnore
     *
     * Create a new instance from an Authority object.
     */
    public static function createFromAuthority(AuthorityInterface|Stringable|string $authority): self
    {
        return self::fromAuthority($authority);
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see Domain::new()
     *
     * @codeCoverageIgnore
     *
     * Returns a new instance from an iterable structure.
     */
    public static function createFromHost(HostInterface $host): self
    {
        return self::new($host);
    }
}
