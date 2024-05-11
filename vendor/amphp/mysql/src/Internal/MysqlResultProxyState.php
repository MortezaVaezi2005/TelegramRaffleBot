<?php declare(strict_types=1);

namespace Amp\Mysql\Internal;

enum MysqlResultProxyState
{
    case Initial;
    case Fetched;
    case Complete;
}
