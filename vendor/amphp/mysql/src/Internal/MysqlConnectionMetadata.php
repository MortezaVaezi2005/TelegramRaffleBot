<?php declare(strict_types=1);

namespace Amp\Mysql\Internal;

/** @internal */
final class MysqlConnectionMetadata
{
    public int $affectedRows = 0;
    public int $insertId = 0;
    public int $statusFlags = 0;
    public ?int $warnings = null;
    public ?string $statusInfo = null;
    public array $sessionState = [];
    public ?string $errorMsg = null;
    public ?int $errorCode = null;
    public ?string $errorState = null; // begins with "#"

    public string $serverVersion = '';

    public int $charset = 0;
}
