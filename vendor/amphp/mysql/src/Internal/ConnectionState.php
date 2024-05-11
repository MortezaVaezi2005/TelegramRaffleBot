<?php declare(strict_types=1);

namespace Amp\Mysql\Internal;

/** @internal */
enum ConnectionState
{
    case Unconnected;
    case Connecting;
    case Established;
    case Ready;
    case Closing;
    case Closed;
}
