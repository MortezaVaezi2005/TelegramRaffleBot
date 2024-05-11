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

use League\Uri\Contracts\AuthorityInterface;
use League\Uri\Contracts\PortInterface;
use League\Uri\Contracts\UriInterface;
use League\Uri\Exceptions\SyntaxError;
use Psr\Http\Message\UriInterface as Psr7UriInterface;
use Stringable;

use function filter_var;

use const FILTER_VALIDATE_INT;

final class Port extends Component implements PortInterface
{
    private readonly ?int $port;

    /**
     * New instance.
     */
    private function __construct(Stringable|string|int|null $port = null)
    {
        $this->port = $this->validate($port);
    }

    public static function new(Stringable|string|int|null $value = null): self
    {
        return new self($value);
    }

    /**
     * Create a new instance from a URI object.
     */
    public static function fromUri(Stringable|string $uri): self
    {
        return new self(self::filterUri($uri)->getPort());
    }

    /**
     * Create a new instance from an Authority object.
     */
    public static function fromAuthority(Stringable|string $authority): self
    {
        return match (true) {
            $authority instanceof AuthorityInterface => new self($authority->getPort()),
            default => new self(Authority::new($authority)->getPort()),
        };
    }

    /**
     * Validate a port.
     *
     * @throws SyntaxError if the port is invalid
     */
    private function validate(Stringable|int|string|null $port): ?int
    {
        $port = self::filterComponent($port);
        if (null === $port) {
            return null;
        }

        $fport = filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (false !== $fport) {
            return $fport;
        }

        throw new SyntaxError('Expected port to be a positive integer or 0; received '.$port.'.');
    }

    public function value(): ?string
    {
        return match (null) {
            $this->port => $this->port,
            default => (string) $this->port,
        };
    }

    public function getUriComponent(): string
    {
        return match (null) {
            $this->port => '',
            default => ':'.$this->value(),
        };
    }

    public function toInt(): ?int
    {
        return $this->port;
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see Port::fromUri()
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
     * @see Port::fromAuthority()
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
     * @see Port::new()
     *
     * @codeCoverageIgnore
     */
    public static function fromInt(int $port): self
    {
        return self::new($port);
    }
}
