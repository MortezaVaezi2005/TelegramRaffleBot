<?php declare(strict_types=1);

require 'vendor/autoload.php';

use Amp\Ipc\Sync\ChannelledSocket;

use function Amp\async;
use function Amp\Ipc\listen;

$clientHandler = function (ChannelledSocket $socket) {
    echo "Accepted connection".PHP_EOL;

    while ($payload = $socket->receive()) {
        echo "Received $payload".PHP_EOL;
        if ($payload === 'ping') {
            $socket->send('pong');
            $socket->disconnect();
        }
    }
    echo "Closed connection".PHP_EOL."==========".PHP_EOL;
};

$server = listen(sys_get_temp_dir().'/test');
while ($socket = $server->accept()) {
    async($clientHandler, $socket);
}
