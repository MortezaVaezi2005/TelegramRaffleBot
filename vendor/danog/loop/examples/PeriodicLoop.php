<?php declare(strict_types=1);

require 'vendor/autoload.php';

use danog\Loop\PeriodicLoop;

use function Amp\delay;

/** @var PeriodicLoop[] */
$loops = [];
for ($x = 0; $x < 10; $x++) {
    $callable = function (PeriodicLoop $loop): bool {
        static $number = 0;
        echo "$loop: $number".PHP_EOL;
        $number++;
        return $number == 10;
    };
    $loop = new PeriodicLoop($callable, "Loop number $x", 1.0);
    $loop->start();
    delay(0.1);
    $loops []= $loop;
}
delay(5);
echo "Resuming prematurely all loops!".PHP_EOL;
foreach ($loops as $loop) {
    $loop->resume();
}
echo "OK done, waiting 5 more seconds!".PHP_EOL;
delay(5);
echo "Closing all loops!".PHP_EOL;
delay(0.01);
