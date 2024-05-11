<?php declare(strict_types=1);

namespace Amp\Mysql\Internal;

use Amp\Cache\AtomicCache;
use Amp\Cache\LocalCache;
use Amp\Sync\LocalKeyedMutex;

/** @internal */
final class PublicKeyCache
{
    private static ?AtomicCache $cache = null;

    public static function loadKey(string $pem): \OpenSSLAsymmetricKey
    {
        self::$cache ??= new AtomicCache(new LocalCache(32), new LocalKeyedMutex());

        return self::$cache->computeIfAbsent($pem, fn () => \openssl_pkey_get_public($pem));
    }

    private function __construct()
    {
    }
}
