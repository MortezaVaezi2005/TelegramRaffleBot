<?php declare(strict_types=1);

namespace Amp\Websocket;

enum WebsocketCount
{
    case BytesReceived;
    case BytesSent;
    case FramesReceived;
    case FramesSent;
    case MessagesReceived;
    case MessagesSent;
    case PingsReceived;
    case PingsSent;
    case PongsReceived;
    case PongsSent;
    case UnansweredPings;
}
