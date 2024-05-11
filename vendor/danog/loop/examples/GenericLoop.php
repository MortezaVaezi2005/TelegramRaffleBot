<?php declare(strict_types=1);

require 'vendor/autoload.php';

use danog\Loop\GenericLoop;

use function Amp\delay;

/** @var GenericLoop[] */
$loops = [];
for ($x = 0; $x < 10; $x++) {
    $callable = function (GenericLoop $loop): float {
        static $number = 0;
        echo "$loop: $number".PHP_EOL;
        $number++;
        return $number < 10 ? 1.0 : GenericLoop::STOP;
    };
    $loop = new GenericLoop($callable, "Loop number $x");
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
