<?php declare(strict_types=1);

namespace Amp\Ipc;

/**
 * Thrown in case server connection fails.
 */
final class IpcServerException extends \Exception
{
    private const TYPE_MAP = [
        IpcServer::TYPE_UNIX => 'UNIX',
        IpcServer::TYPE_TCP => 'TCP',
        IpcServer::TYPE_FIFO => 'FIFO',
    ];
    public function __construct(
        array $messages,
        int $code = 0,
        \Throwable $previous = null
    ) {
        $message = "Could not create IPC server: ";
        foreach ($messages as $type => $error) {
            $message .= self::TYPE_MAP[$type].": $error; ";
        }
        parent::__construct($message, $code, $previous);
    }
}
