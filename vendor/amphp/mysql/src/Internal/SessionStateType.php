<?php declare(strict_types=1);

namespace Amp\Mysql\Internal;

/**
 * @internal
 *
 * @see 13.1.3.1.1 Session State Information
 */
enum SessionStateType: int
{
    case SystemVariables = 0x00;
    case Schema = 0x01;
    case StateChange = 0x02;
    case Gtids = 0x03;
    case TransactionCharacteristics = 0x04;
    case TransactionState = 0x05;
}
