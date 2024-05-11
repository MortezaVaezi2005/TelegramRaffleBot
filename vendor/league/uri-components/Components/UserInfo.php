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
use League\Uri\Contracts\UriComponentInterface;
use League\Uri\Contracts\UriInterface;
use League\Uri\Contracts\UserInfoInterface;
use League\Uri\Encoder;
use League\Uri\Exceptions\SyntaxError;
use Psr\Http\Message\UriInterface as Psr7UriInterface;
use SensitiveParameter;
use Stringable;

use function explode;

final class UserInfo extends Component implements UserInfoInterface
{
    private readonly ?string $username;
    private readonly ?string $password;

    /**
     * New instance.
     */
    public function __construct(
        Stringable|string|null $username,
        #[SensitiveParameter]
        Stringable|string|null $password = null
    ) {
        $this->username = $this->validateComponent($username);
        $password = $this->validateComponent($password);
        if (null === $this->username && null !== $password) {
            throw new SyntaxError('It is not possible to associated a password to an undefined user.');
        }

        $this->password = $password;
    }

    /**
     * Create a new instance from a URI object.
     */
    public static function fromUri(Stringable|string $uri): self
    {
        $uri = self::filterUri($uri);
        $component = $uri->getUserInfo();

        return match (true) {
            $uri instanceof UriInterface, '' !== $component => self::new($component),
            default => self::new(),
        };
    }

    /**
     * Create a new instance from an Authority object.
     */
    public static function fromAuthority(Stringable|string|null $authority): self
    {
        return match (true) {
            $authority instanceof AuthorityInterface => self::new($authority->getUserInfo()),
            default => self::new(Authority::new($authority)->getUserInfo()),
        };
    }

    /**
     * Create a new instance from a hash of parse_url parts.
     *
     * Create a new instance from a hash representation of the URI similar
     * to PHP parse_url function result
     *
     * @param array{user? : ?string, pass? : ?string} $components
     */
    public static function fromComponents(array $components): self
    {
        $components += ['user' => null, 'pass' => null];

        return match (null) {
            $components['user'] => new self(null),
            default => new self($components['user'], $components['pass']),
        };
    }

    /**
     * Creates a new instance from an encoded string.
     */
    public static function new(Stringable|string|null $value = null): self
    {
        if ($value instanceof UriComponentInterface) {
            $value = $value->value();
        }

        if (null === $value) {
            return new self(null);
        }

        $value = (string) $value;

        [$user, $pass] = explode(':', $value, 2) + [1 => null];

        return new self(Encoder::decodeAll($user), Encoder::decodeAll($pass));
    }

    public function value(): ?string
    {
        return match (true) {
            null === $this->username => null,
            null === $this->password => Encoder::encodeUser($this->username),
            default => Encoder::encodeUser($this->username).':'.Encoder::encodePassword($this->password),
        };
    }

    public function getUriComponent(): string
    {
        return $this->value().(null === $this->username ? '' : '@');
    }

    public function getUser(): ?string
    {
        return $this->username;
    }

    public function getPass(): ?string
    {
        return $this->password;
    }

    /**
     * @return array{user: ?string, pass: ?string}
     */
    public function components(): array
    {
        return [
            'user' => $this->username,
            'pass' => $this->password,
        ];
    }

    public function withUser(Stringable|string|null $username): self
    {
        $username = $this->validateComponent($username);

        return match ($this->username) {
            $username => $this,
            default => new self($username, $this->password),
        };
    }

    public function withPass(#[SensitiveParameter] Stringable|string|null $password): self
    {
        $password = $this->validateComponent($password);

        return match (true) {
            $password === $this->password => $this,
            null === $this->username => throw new SyntaxError('It is not possible to associated a password to an undefined user.'),
            default => new self($this->username, $password),
        };
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see UserInfo::fromUri()
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
     * @see UserInfo::fromAuthority()
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
     * @see UserInfo::new()
     *
     * @codeCoverageIgnore
     *
     * Creates a new instance from an encoded string.
     */
    public static function createFromString(Stringable|string $userInfo): self
    {
        return self::new($userInfo);
    }
}
