<?php

require __DIR__ . '/../../vendor/autoload.php';

use Amp\Http\Internal;

$fuzzer->setTarget(function (string $input) {
    $result1 = (new Internal\HPackNghttp2)->decode($input, 8192);
    $result2 = (new Internal\HPackNative)->decode($input, 8192);

    if ($result1 === null || $result2 === null) {
        return;
    }

    if ($result1 !== $result2) {
        throw new \Error('Mismatch: ' . var_export($result1, true) . ' / ' . var_export($result2, true));
    }
});

$fuzzer->setMaxLen(1024);
