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
use League\Uri\Contracts\PathInterface;
use League\Uri\Contracts\SegmentedPathInterface;
use League\Uri\Contracts\UriInterface;
use League\Uri\Encoder;
use League\Uri\Exceptions\OffsetOutOfBounds;
use League\Uri\Exceptions\SyntaxError;
use Psr\Http\Message\UriInterface as Psr7UriInterface;
use Stringable;
use TypeError;

use function array_count_values;
use function array_filter;
use function array_keys;
use function array_pop;
use function array_unshift;
use function count;
use function dirname;
use function explode;
use function implode;
use function ltrim;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strrpos;
use function substr;

use const ARRAY_FILTER_USE_KEY;
use const FILTER_VALIDATE_INT;
use const PATHINFO_EXTENSION;

final class HierarchicalPath extends Component implements SegmentedPathInterface
{
    private const SEPARATOR = '/';
    private const IS_ABSOLUTE = 1;
    private const IS_RELATIVE = 0;
    private readonly PathInterface $path;
    /** @var array<string> */
    private readonly array $segments;

    private function __construct(Stringable|string $path)
    {
        if (!$path instanceof PathInterface) {
            $path = Path::new($path);
        }

        $this->path = $path;
        $segments = $this->path->decoded();
        if ($this->path->isAbsolute()) {
            $segments = substr($segments, 1);
        }

        $this->segments = explode(self::SEPARATOR, $segments);
    }

    /**
     * Returns a new instance from a string or a stringable object.
     */
    public static function new(Stringable|string $value = ''): self
    {
        return new self($value);
    }

    /**
     * Create a new instance from a URI object.
     */
    public static function fromUri(Stringable|string $uri): self
    {
        return new self(Path::fromUri($uri));
    }

    /**
     * Returns a new instance from an iterable structure.
     *
     * @throws TypeError If the segments are malformed
     */
    public static function fromRelative(string ...$segments): self
    {
        return self::fromSegments(self::IS_RELATIVE, $segments);
    }

    /**
     * Returns a new instance from an iterable structure.
     *
     * @throws TypeError If the segments are malformed
     */
    public static function fromAbsolute(string ...$segments): self
    {
        return self::fromSegments(self::IS_ABSOLUTE, $segments);
    }

    /**
     * @param array<string> $segments
     */
    private static function fromSegments(int $pathType, array $segments): self
    {
        $pathSegments = array_map(fn (Stringable|string $segment): string => (string) $segment, $segments);
        $path = implode(self::SEPARATOR, $pathSegments);

        return match (true) {
            self::IS_RELATIVE === $pathType => new self(ltrim($path, self::SEPARATOR)),
            self::SEPARATOR !== ($path[0] ?? '') => new self(self::SEPARATOR.$path),
            default => new self($path),
        };
    }

    public function count(): int
    {
        return count($this->segments);
    }

    public function getIterator(): Iterator
    {
        yield from $this->segments;
    }

    public function isAbsolute(): bool
    {
        return $this->path->isAbsolute();
    }

    public function hasTrailingSlash(): bool
    {
        return $this->path->hasTrailingSlash();
    }

    public function value(): ?string
    {
        return $this->path->value();
    }

    public function decoded(): string
    {
        return $this->path->decoded();
    }

    public function getDirname(): string
    {
        $path = $this->path->decoded();

        return str_replace(
            ['\\', "\0"],
            [self::SEPARATOR, '\\'],
            dirname(str_replace('\\', "\0", $path))
        );
    }

    public function getBasename(): string
    {
        $data = $this->segments;
        $basename = (string) array_pop($data);
        $pos = strpos($basename, ';');

        return match (false) {
            $pos => $basename,
            default => substr($basename, 0, $pos),
        };
    }

    public function getExtension(): string
    {
        [$basename] = explode(';', $this->getBasename(), 2);

        return pathinfo($basename, PATHINFO_EXTENSION);
    }

    public function get(int $offset): ?string
    {
        if ($offset < 0) {
            $offset += count($this->segments);
        }

        return $this->segments[$offset] ?? null;
    }

    public function keys(Stringable|string|null $segment = null): array
    {
        $segment = self::filterComponent($segment);

        return match (null) {
            $segment => array_keys($this->segments),
            default => array_keys($this->segments, $segment, true),
        };
    }

    public function withoutDotSegments(): PathInterface
    {
        $path = $this->path->withoutDotSegments();

        return match ($this->path) {
            $path => $this,
            default =>  new self($path),
        };
    }

    public function withLeadingSlash(): PathInterface
    {
        $path = $this->path->withLeadingSlash();

        return match ($this->path) {
            $path => $this,
            default =>  new self($path),
        };
    }

    public function withoutLeadingSlash(): PathInterface
    {
        $path = $this->path->withoutLeadingSlash();

        return match ($this->path) {
            $path => $this,
            default =>  new self($path),
        };
    }

    public function withoutTrailingSlash(): PathInterface
    {
        $path = $this->path->withoutTrailingSlash();

        return match ($this->path) {
            $path => $this,
            default =>  new self($path),
        };
    }

    public function withTrailingSlash(): PathInterface
    {
        $path = $this->path->withTrailingSlash();

        return match ($this->path) {
            $path => $this,
            default =>  new self($path),
        };
    }

    public function append(Stringable|string $segment): SegmentedPathInterface
    {
        /** @var string $segment */
        $segment = self::filterComponent($segment);

        return new self(
            rtrim($this->path->toString(), self::SEPARATOR)
            .self::SEPARATOR
            .ltrim($segment, self::SEPARATOR)
        );
    }

    public function prepend(Stringable|string $segment): SegmentedPathInterface
    {
        /** @var string $segment */
        $segment = self::filterComponent($segment);

        return new self(
            rtrim($segment, self::SEPARATOR)
            .self::SEPARATOR
            .ltrim($this->path->toString(), self::SEPARATOR)
        );
    }

    public function withSegment(int $key, Stringable|string $segment): SegmentedPathInterface
    {
        $nbSegments = count($this->segments);
        if ($key < - $nbSegments - 1 || $key > $nbSegments) {
            throw new OffsetOutOfBounds(sprintf('The given key `%s` is invalid.', $key));
        }

        if (0 > $key) {
            $key += $nbSegments;
        }

        if ($nbSegments === $key) {
            return $this->append($segment);
        }

        if (-1 === $key) {
            return $this->prepend($segment);
        }

        if (!$segment instanceof PathInterface) {
            $segment = new self($segment);
        }

        $segment = Encoder::decodeAll($segment);
        if ($segment === $this->segments[$key]) {
            return $this;
        }

        $segments = $this->segments;
        $segments[$key] = $segment;
        if ($this->isAbsolute()) {
            array_unshift($segments, '');
        }

        return new self(implode(self::SEPARATOR, $segments));
    }

    public function withoutEmptySegments(): SegmentedPathInterface
    {
        /** @var string $path */
        $path = preg_replace(',/+,', self::SEPARATOR, $this->toString());

        return new self($path);
    }

    public function withoutSegment(int ...$keys): SegmentedPathInterface
    {
        if ([] === $keys) {
            return $this;
        }
        $nb_segments = count($this->segments);
        $options = ['options' => ['min_range' => - $nb_segments, 'max_range' => $nb_segments - 1]];
        $deleted_keys = [];
        foreach ($keys as $value) {
            /** @var false|int $offset */
            $offset = filter_var($value, FILTER_VALIDATE_INT, $options);
            if (false === $offset) {
                throw new OffsetOutOfBounds(sprintf('The key `%s` is invalid.', $value));
            }

            if ($offset < 0) {
                $offset += $nb_segments;
            }
            $deleted_keys[] = $offset;
        }

        $deleted_keys = array_keys(array_count_values($deleted_keys));
        $filter = static fn ($key): bool => !in_array($key, $deleted_keys, true);

        $path = implode(self::SEPARATOR, array_filter($this->segments, $filter, ARRAY_FILTER_USE_KEY));
        if ($this->isAbsolute()) {
            return new self(self::SEPARATOR.$path);
        }

        return new self($path);
    }

    public function slice(int $offset, ?int $length = null): self
    {
        $nbSegments = count($this->segments);
        if ($offset < -$nbSegments || $offset > $nbSegments) {
            throw new OffsetOutOfBounds(sprintf('No segment can be found with at : `%s`.', $offset));
        }

        $segments = array_slice($this->segments, $offset, $length, true);
        if ($this->hasTrailingSlash()) {
            $segments[] = '';
        }

        return match (true) {
            $segments === $this->segments => $this,
            $this->isAbsolute() => self::fromAbsolute(...$segments),
            default => self::fromRelative(...$segments),
        };
    }

    public function withDirname(Stringable|string $path): SegmentedPathInterface
    {
        if (!$path instanceof PathInterface) {
            $path = Path::new($path);
        }

        if ($path->value() === $this->getDirname()) {
            return $this;
        }

        $segments = $this->segments;

        return new self(
            rtrim($path->toString(), self::SEPARATOR)
            .self::SEPARATOR
            .array_pop($segments)
        );
    }

    public function withBasename(Stringable|string $basename): SegmentedPathInterface
    {
        /** @var string $basename */
        $basename = $this->validateComponent($basename);

        return match (true) {
            str_contains($basename, self::SEPARATOR) => throw new SyntaxError('The basename cannot contain the path separator.'),
            default => $this->withSegment(count($this->segments) - 1, $basename),
        };
    }

    public function withExtension(Stringable|string $extension): SegmentedPathInterface
    {
        /** @var string $extension */
        $extension = $this->validateComponent($extension);
        if (str_contains($extension, self::SEPARATOR)) {
            throw new SyntaxError('An extension sequence cannot contain a path delimiter.');
        }

        if (str_starts_with($extension, '.')) {
            throw new SyntaxError('An extension sequence cannot contain a leading `.` character.');
        }

        /** @var string $basename */
        $basename = $this->segments[array_key_last($this->segments)];
        [$ext, $param] = explode(';', $basename, 2) + [1 => null];
        if ('' === $ext) {
            return $this;
        }

        return $this->withBasename($this->buildBasename($extension, (string) $ext, $param));
    }

    /**
     * Creates a new basename with a new extension.
     */
    private function buildBasename(string $extension, string $ext, ?string $param = null): string
    {
        $length = strrpos($ext, '.'.pathinfo($ext, PATHINFO_EXTENSION));
        if (false !== $length) {
            $ext = substr($ext, 0, $length);
        }

        if (null !== $param && '' !== $param) {
            $param = ';'.$param;
        }

        $extension = trim($extension);
        if ('' === $extension) {
            return $ext.$param;
        }

        return $ext.'.'.$extension.$param;
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
    public function segments(): array
    {
        return $this->segments;
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see HierarchicalPath::new()
     *
     * @codeCoverageIgnore
     *
     * Returns a new instance from a string or a stringable object.
     */
    public static function createFromString(Stringable|string $path): self
    {
        return self::new($path);
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see HierarchicalPath::new()
     *
     * @codeCoverageIgnore
     */
    public static function createFromPath(PathInterface $path): self
    {
        return self::new($path);
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @throws TypeError If the segments are malformed
     *@see HierarchicalPath::fromRelative()
     *
     * @codeCoverageIgnore
     *
     * Returns a new instance from an iterable structure.
     *
     * @deprecated Since version 7.0.0
     */
    public static function createRelativeFromSegments(iterable $segments): self
    {
        return self::fromRelative(...$segments);
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @throws TypeError If the segments are malformed
     *@see HierarchicalPath::fromAbsolute()
     *
     * @codeCoverageIgnore
     *
     * Returns a new instance from an iterable structure.
     *
     * @deprecated Since version 7.0.0
     */
    public static function createAbsoluteFromSegments(iterable $segments): self
    {
        return self::fromAbsolute(...$segments);
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @deprecated Since version 7.0.0
     * @see HierarchicalPath::fromUri()
     *
     * @codeCoverageIgnore
     *
     * Create a new instance from a URI object.
     */
    public static function createFromUri(Psr7UriInterface|UriInterface $uri): self
    {
        return self::fromUri($uri);
    }
}
