<?php

/*
Copyright 2016-2018 Daniil Gentili
(https://daniil.it)
This file is part of MadelineProto.
MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
See the GNU Affero General Public License for more details.
You should have received a copy of the GNU General Public License along with the MadelineProto.
If not, see <http://www.gnu.org/licenses/>.
*/

namespace danog;

use FFI;

class PrimeModule
{
    public static function native_single($what)
    {
        if (!is_int($what)) {
            return false;
        }
        foreach ([2, 3, 5, 7, 11, 13, 17, 19, 23] as $s) {
            if ($what % $s === 0) {
                return $s;
            }
        }
        $g = 0;
        $it = 0;
        for ($i = 0; $i < 3 || $it < 1000; $i++) {
            $t = ((rand(0, 127) & 15) + 17) % $what;
            $x = (rand() % ($what - 1)) + 1;
            $y = $x;
            $lim = 1 << ($i + 18);
            for ($j = 1; $j <= $lim; $j++) {
                $it++;
                $a = $x;
                $b = $x;
                $c = $t;
                while ($b) {
                    if ($b & 1) {
                        $c += $a;
                        if ($c >= $what) {
                            $c -= $what;
                        }
                    }
                    $a += $a;
                    if ($a >= $what) {
                        $a -= $what;
                    }
                    $b >>= 1;
                }
                $x = $c;
                $z = ($x < $y) ? $what + $x - $y : $x - $y;
                $g = self::gcd($z, $what);
                if ($g != 1) {
                    break;
                }

                if (!($j & ($j - 1))) {
                    $y = $x;
                }
            }
            if ($g > 1 && $g < $what) {
                break;
            }
        }

        if ($g > 1 && $g < $what) {
            return $g;
        }

        return $what;
    }

    public static function native($what)
    {
        $res = [self::native_single($what)];
        while (array_product($res) !== $what) {
            $res[] = self::native_single($what / array_product($res));
        }

        return $res;
    }

    public static function python_single($what)
    {
        if (function_exists('shell_exec')) {
            $res = trim(shell_exec('timeout 10 python2 '.__DIR__.'/prime.py '.$what.' 2>&1') ?? '');
            if ($res == '' || is_null($res) || !is_numeric($res)) {
                copy(__DIR__.'/prime.py', getcwd().'/.prime.py');
                $res = trim(shell_exec('timeout 10 python2 '.getcwd().'/.prime.py '.$what.' 2>&1') ?? '');
                unlink(getcwd().'/.prime.py');
                if ($res == '' || is_null($res) || !is_numeric($res)) {
                    return false;
                }
            }
            $newval = intval($res);
            if (is_int($newval)) {
                $res = $newval;
            }
            if ($res === 0) {
                return false;
            }

            return $res;
        }

        return false;
    }

    public static function factor_single($what)
    {
        if (function_exists('shell_exec')) {
            $res = trim(shell_exec('timeout 10 factor '.$what.' 2>&1') ?? '');
            $res = explode(' ', $res);
            if (count($res) !== 3) {
                return false;
            }
            return (int) $res[1];
        }

        return false;
    }

    public static function factor($what)
    {
        $res = [self::factor_single($what)];
        if ($res[0] === false) {
            return false;
        }
        while (array_product($res) !== $what) {
            $res[] = self::factor_single($what / array_product($res));
        }

        return $res;
    }

    public static function python($what)
    {
        $res = [self::python_single($what)];
        if ($res[0] === false) {
            return false;
        }
        while (array_product($res) !== $what) {
            $res[] = self::python_single($what / array_product($res));
        }

        return $res;
    }

    public static function python_single_alt($what)
    {
        if (function_exists('shell_exec')) {
            $res = trim(shell_exec('python '.__DIR__.'/alt_prime.py '.$what.' 2>&1') ?? '');
            if ($res == '' || is_null($res) || !is_numeric($res)) {
                copy(__DIR__.'/alt_prime.py', getcwd().'/.alt_prime.py');
                $res = trim(shell_exec('python '.getcwd().'/.alt_prime.py '.$what.' 2>&1') ?? '');
                unlink(getcwd().'/.alt_prime.py');
                if ($res == '' || is_null($res) || !is_numeric($res)) {
                    return false;
                }
            }
            $newval = intval($res);
            if (is_int($newval)) {
                $res = $newval;
            }
            if ($res === 0) {
                return false;
            }

            return $res;
        }

        return false;
    }

    public static function python_alt($what)
    {
        $res = [self::python_single_alt($what)];
        if ($res[0] === false) {
            return false;
        }
        while (array_product($res) !== $what) {
            $res[] = self::python_single_alt($what / array_product($res));
        }

        return $res;
    }

    public static function wolfram_single($what)
    {
        $query = 'Do prime factorization of '.$what;
        $params = [
            'async'         => true,
            'banners'       => 'raw',
            'debuggingdata' => false,
            'format'        => 'moutput',
            'formattimeout' => 8,
            'input'         => $query,
            'output'        => 'JSON',
            'proxycode'     => json_decode(file_get_contents('http://www.wolframalpha.com/api/v1/code'), true)['code'],
        ];
        $url = 'https://www.wolframalpha.com/input/json.jsp?'.http_build_query($params);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Referer: https://www.wolframalpha.com/input/?i='.urlencode($query)]);
        curl_setopt($ch, CURLOPT_URL, $url);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);
        $fres = false;
        if (!isset($res['queryresult']['pods'])) {
            return false;
        }
        foreach ($res['queryresult']['pods'] as $cur) {
            if ($cur['id'] === 'Divisors') {
                $fres = explode(', ', preg_replace(["/{\d+, /", "/, \d+}$/"], '', $cur['subpods'][0]['moutput']));
                break;
            }
        }
        if (is_array($fres)) {
            $fres = $fres[0];

            $newval = intval($fres);
            if (is_int($newval)) {
                $fres = $newval;
            }

            return $fres;
        }

        return false;
    }

    public static function wolfram($what)
    {
        $res = [self::wolfram_single($what)];
        while (array_product($res) !== $what) {
            $res[] = self::wolfram_single($what / array_product($res));
        }

        return $res;
    }

    private static ?FFI $ffi = null;

    public static function native_single_cpp($what)
    {
        if (!extension_loaded('primemodule')) {
            if (class_exists(FFI::class)) {
                try {
                    self::$ffi ??= FFI::load('/usr/include/primemodule-ffi.h');
                    $result = self::$ffi->factorizeFFI((string) $what);
                    if ($result > 0) {
                        return $result;
                    }
                } catch (\Throwable $e) {
                }

                return false;
            }

            return false;
        }

        try {
            return factorize($what);
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function native_cpp($what)
    {
        $res = [self::native_single_cpp($what)];
        if ($res[0] == false) {
            return false;
        }
        while (($product = array_product($res)) !== $what) {
            if ($product == 0) {
                return false;
            }
            $res[] = self::native_single_cpp($what / $product);
        }

        return $res;
    }

    public static function auto_single($what)
    {
        $res = self::native_single_cpp($what);
        if ($res !== false) {
            return $res;
        }
        $res = self::python_single_alt($what);
        if ($res !== false) {
            return $res;
        }
        $res = self::python_single($what);
        if ($res !== false) {
            return $res;
        }
        $res = self::native_single((int) $what);
        if ($res !== false) {
            return $res;
        }
        $res = self::wolfram_single($what);
        if ($res !== false) {
            return $res;
        }

        return false;
    }

    public static function auto($what)
    {
        $res = self::native_cpp($what);
        if (is_array($res)) {
            return $res;
        }
        $res = self::python_alt($what);
        if (is_array($res)) {
            return $res;
        }
        $res = self::python($what);
        if (is_array($res)) {
            return $res;
        }
        $res = self::native((int) $what);
        if (is_array($res)) {
            return $res;
        }
        $res = self::wolfram($what);
        if (is_array($res)) {
            return $res;
        }

        return false;
    }

    private static function gcd($a, $b)
    {
        if ($a == $b) {
            return $a;
        }
        while ($b > 0) {
            list($a, $b) = [$b, self::posmod($a, $b)];
        }

        return $a;
    }

    private static function posmod($a, $b)
    {
        $resto = $a % $b;
        if ($resto < 0) {
            $resto += abs($b);
        }

        return $resto;
    }

    private function primesbelow($N)
    {
        $correction = ($N % 6 > 1) ? true : false;
        $N_Array = [$N, $N - 1, $N + 4, $N + 3, $N + 2, $N + 1];
        $N = $N_Array[$N % 6];

        $sieve = [];
        for ($i = 0; $i < (int) $N / 3; $i++) {
            $sieve[$i] = true;
        }
        $sieve[0] = false;

        for ($i = 0; $i < (int) ((int) pow($N, 0.5) / 3) + 1; $i++) {
            if ($sieve[$i]) {
                $k = (3 * $i + 1) | 1;
                $startIndex1 = (int) ($k * $k / 3);
                $period = 2 * $k;
                for ($j = $startIndex1; $j < count($sieve); $j = $j + $period) {
                    $sieve[$j] = false;
                }
                $startIndex2 = (int) (($k * $k + 4 * $k - 2 * $k * ($i % 2)) / 3);
                $period = 2 * $k;
                for ($k = $startIndex2; $k < count($sieve); $k = $k + $period) {
                    $sieve[$k] = false;
                }
            }
        }

        $resultArray = [2, 3];
        $t = 1;
        for ($i = 1; $i < (int) ($N / 3) - $correction; $i++) {
            if ($sieve[$i]) {
                $resultArray[$t + 1] = (3 * $i + 1) | 1;
                $t++;
            }
        }

        return $resultArray;
    }

    private function isprime($n, $precision = 7)
    {
        $smallprimeset = $this->primesbelow(100000);
        $_smallprimeset = 100000;
        if ($n == 1 || $n % 2 == 0) {
            return false;
        } elseif ($n < 1) {
            throw new Exception('Out of bounds, first argument must be > 0');
        } elseif ($n < $_smallprimeset) {
            return in_array($n, $smallprimeset);
        }

        $d = $n - 1;
        $s = 0;
        while ($d % 2 == 0) {
            $d = (int) ($d / 2);
            $s += 1;
        }

        for ($i = 0; $i < $precision; $i++) {
            // random.randrange(2, n - 2) means:
            // $a = mt_rand(2, $n - 3);
            // maybe $n would be bigger that PHP_MAX_INT
            $a = mt_rand(2, 1084);
            $x = bcpowmod($a, $d, $n);

            if ($x == 1 || $x == $n - 1) {
                continue;
            }

            $flagfound = 0;
            for ($j = 0; $j < $s - 1; $j++) {
                $x = bcpowmod($x, 2, $n);
                if ($x == 1) {
                    return false;
                }
                if ($x == $n - 1) {
                    $flagfound = 1;
                    break;
                }
            }
            if ($flagfound == 0) {
                return false;
            }
        }

        return true;
    }

    private function pollard_brent($n)
    {
        $n = (int) $n;

        if ($n % 2 == 0) {
            return 2;
        }

        if (bcmod($n, 2) == 0) {
            return 2;
        }
        if (bcmod($n, 3) == 0) {
            return 3;
        }

        // $y = mt_rand(1, $n-1);
        // $c = mt_rand(1, $n-1);
        // $m = mt_rand(1, $n-1);
        // Again, $n may be bigger than PHP_MAX_INT
        // also, small numbers has a big affect in a good performance
        $y = 2;
        $c = 3;
        $m = 4;

        $g = 1;
        $r = 1;
        $q = 1;

        while ($g == 1) {
            $x = $y;
            for ($i = 0; $i < $r; $i++) {
                // $y = gmp_mod( (bcpowmod($y, 2, $n) + $c) , $n);
                $y = bcmod((bcpowmod($y, 2, $n) + $c), $n);
            }

            $k = 0;
            while ($k < $r && $g == 1) {
                $ys = $y;
                for ($j = 0; $j < min($m, $r - $k); $j++) {

                    // $y = gmp_mod( (bcpowmod($y, 2, $n) + $c), $n );
                    $y = bcmod((bcpowmod($y, 2, $n) + $c), $n);

                    // $q = gmp_mod($q * abs($x-$y), $n);
                    $mul = bcmul($q, abs($x - $y));
                    $q = bcmod($mul, $n);
                }

                $g = $this->gcd2($q, $n);
                $k += $m;
            }
            $r *= 2;
        }
        if ($g == $n) {
            while (true) {
                // $ys = ( bcpowmod($ys, 2, $n) + $c ) % $n;
                $ys = bcmod((bcpowmod($ys, 2, $n) + $c), $n);
                $g = $this->gcd2(abs($x - $ys), $n);
                if ($g > 1) {
                    break;
                }
            }
        }

        return $g;
    }

    public function primefactors($n, $sort = false)
    {
        $smallprimes = $this->primesbelow(10000);
        $factors = [];

        $limit = bcadd(bcsqrt($n), 1);
        foreach ($smallprimes as $checker) {
            if ($checker > $limit) {
                break;
            }
            // while (gmp_mod($n, $checker) == 0) {
            while (bcmod($n, $checker) == 0) {
                array_push($factors, $checker);
                $n = bcdiv($n, $checker);
                $limit = bcadd(bcsqrt($n), 1);
                if ($checker > $limit) {
                    break;
                }
            }
        }

        if ($n < 2) {
            return $factors;
        }

        while ($n > 1) {
            if ($this->isprime($n)) {
                array_push($factors, $n);
                break;
            }
            $factor = $this->pollard_brent($n);
            $factors = array_merge($factors, $this->primefactors($factor));
            $n = (int) ($n / $factor);
        }
        if ($sort) {
            sort($factors);
        }

        return $factors;
    }

    private function gcd2($a, $b)
    {
        if ($a == $b) {
            return $a;
        }
        while ($b > 0) {
            $a2 = $a;
            $a = $b;
            $b = bcmod($a2, $b);
        }

        return $a;
    }
}
