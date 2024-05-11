<?php declare(strict_types=1);

namespace Amp\Redis\Internal;

/** @internal */
function toMap(?array $values): array
{
    if ($values === null) {
        return [];
    }

    $size = \count($values);
    $result = [];

    for ($i = 0; $i < $size; $i += 2) {
        $result[$values[$i]] = $values[$i + 1];
    }

    return $result;
}
