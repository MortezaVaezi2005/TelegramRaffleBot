<?php declare(strict_types=1);

namespace Amp\Ipc;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Ipc\Sync\ChannelInitAck;
use Amp\Ipc\Sync\ChannelledSocket;
use Amp\TimeoutCancellation;
use AssertionError;

use function Amp\async;

/**
 * Create IPC server.
 *
 * @param string $uri Local endpoint on which to listen for requests
 *
 */
function listen(string $uri): IpcServer
{
    return new IpcServer($uri);
}

/**
 * Connect to IPC server.
 *
 * @param string $uri URI
 */
function connect(string $uri, ?Cancellation $cancellation = null): ChannelledSocket
{
    if (!\file_exists($uri)) {
        throw new \RuntimeException("The endpoint does not exist!");
    }

    do {
        $type = \filetype($uri);
        if ($type !== 'fifo') {
            if ($type === 'file') {
                $uri = \file_get_contents($uri);
            } else {
                $uri = "unix://$uri";
            }
            if (!$socket = \stream_socket_client($uri, $errno, $errstr, 5, \STREAM_CLIENT_CONNECT)) {
                $message = "Could not connect to IPC socket";
                if ($error = \error_get_last()) {
                    $message .= \sprintf(" Errno: %d; %s", $error["type"], $error["message"]);
                }
                throw new \RuntimeException($message);
            }
            return new ChannelledSocket(
                new ReadableResourceStream($socket),
                new WritableResourceStream($socket)
            );
        }

        $suffix = \bin2hex(\random_bytes(10));
        $prefix = \sys_get_temp_dir()."/amp-".$suffix.".fifo";

        if (\strlen($prefix) > 0xFFFF) {
            throw new \RuntimeException('Prefix is too long!');
        }

        $sockets = [];

        foreach ([
            $prefix."2",
            $prefix."1",
        ] as $k => $socket) {
            if (!\posix_mkfifo($socket, 0777)) {
                throw new \RuntimeException('Could not create FIFO client socket!');
            }

            \register_shutdown_function(static function () use ($socket): void {
                @\unlink($socket);
            });

            if (!$sockets[$k] = \fopen($socket, 'r+')) { // Open in r+w mode to prevent blocking if there is no reader
                throw new \RuntimeException("Could not open FIFO client socket");
            }
        }

        if (!$tempSocket = \fopen($uri, 'r+')) { // Open in r+w mode to prevent blocking if there is no reader
            throw new \RuntimeException("Could not connect to FIFO server");
        }
        \stream_set_blocking($tempSocket, false);
        \stream_set_write_buffer($tempSocket, 0);

        if (!\fwrite($tempSocket, \pack('v', \strlen($prefix)).$prefix)) {
            \fclose($tempSocket);
            unset($tempSocket);
            throw new \RuntimeException("Failure sending request to FIFO server");
        }
        \fclose($tempSocket);
        unset($tempSocket);

        $socket = new ChannelledSocket(
            new ReadableResourceStream($sockets[0]),
            new WritableResourceStream($sockets[1]),
        );

        try {
            $result = async($socket->receive(...))->await(
                $cancellation
                ? new CompositeCancellation(
                    $cancellation,
                    new TimeoutCancellation(0.5)
                )
                : new TimeoutCancellation(0.5)
            );
            if (!$result instanceof ChannelInitAck) {
                throw new \RuntimeException('Missing init ack!');
            }
            return $socket;
        } catch (CancelledException $e) {
            if ($cancellation?->isRequested()) {
                throw $e;
            }
        }
    } while (true);

    throw new AssertionError("Unreachable!");
}
