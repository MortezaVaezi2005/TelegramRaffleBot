<?php declare(strict_types=1);

namespace Amp\Http\Client\Cookie;

use Amp\ByteStream;
use Amp\File;
use Amp\File\Filesystem;
use Amp\Future;
use Amp\Http\Client\HttpException;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Sync\LocalMutex;
use Amp\Sync\Mutex;
use Psr\Http\Message\UriInterface as PsrUri;
use function Amp\async;

final class FileCookieJar implements CookieJar
{
    /** @var Future<LocalCookieJar>|null */
    private ?Future $cookieJar = null;

    private bool $persistSessionCookies = false;

    private readonly Filesystem $filesystem;

    public function __construct(
        private readonly string $storagePath,
        private readonly Mutex $mutex = new LocalMutex(),
        ?Filesystem $filesystem = null
    ) {
        if (!\class_exists(Filesystem::class)) {
            throw new \Error(self::class . ' requires amphp/file to be installed. Run composer require amphp/file to install it.');
        }

        $this->filesystem = $filesystem ?? File\filesystem();
    }

    public function enableSessionCookiePersistence(): void
    {
        $this->persistSessionCookies = true;
    }

    public function disableSessionCookiePersistence(): void
    {
        $this->persistSessionCookies = false;
    }

    public function get(PsrUri $uri): array
    {
        $cookieJar = $this->read();
        return $cookieJar->get($uri);
    }

    public function store(ResponseCookie ...$cookies): void
    {
        $cookieJar = $this->read();

        $cookieJar->store(...$cookies);

        $this->write($cookieJar);
    }

    private function read(): LocalCookieJar
    {
        $this->cookieJar ??= async(function (): LocalCookieJar {
            $lock = $this->mutex->acquire();

            $cookieJar = new LocalCookieJar;

            if (!$this->filesystem->exists($this->storagePath)) {
                return $cookieJar;
            }

            $file = $this->filesystem->openFile($this->storagePath, 'r');

            foreach (ByteStream\splitLines($file) as $line) {
                $line = \trim($line);

                if ($line) {
                    $cookie = ResponseCookie::fromHeader($line);
                    if ($cookie === null) {
                        continue;
                    }

                    try {
                        $cookieJar->store($cookie);
                    } catch (HttpException $e) {
                        // ignore invalid cookies in storage
                    }
                }
            }

            $file->close();
            $lock->release();

            return $cookieJar;
        });

        return $this->cookieJar->await();
    }

    private function write(LocalCookieJar $cookieJar): void
    {
        $lock = $this->mutex->acquire();

        if (!$this->filesystem->isDirectory(\dirname($this->storagePath))) {
            $this->filesystem->createDirectoryRecursively(\dirname($this->storagePath), 0755);

            if (!$this->filesystem->isDirectory(\dirname($this->storagePath))) {
                throw new HttpException('Failed to create cookie storage directory: ' . $this->storagePath);
            }
        }

        $now = \time();
        $file = $this->filesystem->openFile($this->storagePath, 'w');
        foreach ($cookieJar->getAll() as $cookie) {
            $expiry = $cookie->getExpiry();
            if ($expiry ? $expiry->getTimestamp() > $now : $this->persistSessionCookies) {
                $file->write($cookie . "\r\n");
            }
        }

        $file->close();
        $lock->release();
    }
}
