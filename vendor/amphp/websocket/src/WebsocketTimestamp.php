<?php declare(strict_types=1);

namespace Amp\Websocket;

enum WebsocketTimestamp
{
    case Connected;
    case Closed;
    case LastRead;
    case LastSend;
    case LastDataRead;
    case LastDataSend;
    case LastHeartbeat;
}
