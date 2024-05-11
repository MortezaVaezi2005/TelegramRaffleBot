<?php declare(strict_types=1);

require 'vendor/autoload.php';

use danog\Loop\GenericLoop;
use danog\Loop\Loop;

use function Amp\delay;

class MyLoop extends Loop
{
    private int $number = 0;

    public function __construct(private string $name)
    {
    }

    protected function loop(): ?float
    {
        echo "$this: {$this->number}".PHP_EOL;
        $this->number++;
        return $this->number < 10 ? 1.0 : GenericLoop::STOP;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}

/** @var MyLoop[] */
$loops = [];
for ($x = 0; $x < 10; $x++) {
    $loop = new MyLoop("Loop number $x");
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
