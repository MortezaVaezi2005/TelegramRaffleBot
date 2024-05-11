<?php

declare(strict_types=1);

namespace Prometheus;

class Math
{
    /**
     * taken from https://www.php.net/manual/fr/function.stats-stat-percentile.php#79752
     * @param float[] $arr must be sorted
     * @param float $q
     *
     * @return float
     */
    public function quantile(array $arr, float $q): float
    {
        $count = count($arr);
        if ($count === 0) {
            return 0;
        }

        $j = floor($count * $q);
        $r = $count * $q - $j;
        if (0.0 === $r) {
            return $arr[$j - 1];
        }
        return $arr[$j];
    }
}
