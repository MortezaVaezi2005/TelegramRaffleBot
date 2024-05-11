<?php declare(strict_types=1);

namespace Amp\Websocket;

final class WebsocketCloseCode
{
    public const NORMAL_CLOSE = 1000;
    public const GOING_AWAY = 1001;
    public const PROTOCOL_ERROR = 1002;
    public const UNACCEPTABLE_TYPE = 1003;
    // 1004 reserved and unused.
    public const NONE = 1005;
    public const ABNORMAL_CLOSE = 1006;
    public const INCONSISTENT_FRAME_DATA_TYPE = 1007;
    public const POLICY_VIOLATION = 1008;
    public const MESSAGE_TOO_LARGE = 1009;
    public const EXPECTED_EXTENSION_MISSING = 1010;
    public const UNEXPECTED_SERVER_ERROR = 1011;
    public const SERVICE_RESTARTING = 1012;
    public const TRY_AGAIN_LATER = 1013;
    public const BAD_GATEWAY = 1014;
    public const TLS_HANDSHAKE_FAILURE = 1015;

    /**
     * @param int $code Close code.
     *
     * @return string|null Constant name corresponding to the given code or null if the code is undefined.
     */
    public static function getName(int $code): ?string
    {
        return match ($code) {
            self::NORMAL_CLOSE => 'NORMAL_CLOSE',
            self::GOING_AWAY => 'GOING_AWAY',
            self::PROTOCOL_ERROR => 'PROTOCOL_ERROR',
            self::UNACCEPTABLE_TYPE => 'UNACCEPTABLE_TYPE',
            self::NONE => 'NONE',
            self::ABNORMAL_CLOSE => 'ABNORMAL_CLOSE',
            self::INCONSISTENT_FRAME_DATA_TYPE => 'INCONSISTENT_FRAME_DATA_TYPE',
            self::POLICY_VIOLATION => 'POLICY_VIOLATION',
            self::MESSAGE_TOO_LARGE => 'MESSAGE_TOO_LARGE',
            self::EXPECTED_EXTENSION_MISSING => 'EXPECTED_EXTENSION_MISSING',
            self::UNEXPECTED_SERVER_ERROR => 'UNEXPECTED_SERVER_ERROR',
            self::SERVICE_RESTARTING => 'SERVICE_RESTARTING',
            self::TRY_AGAIN_LATER => 'TRY_AGAIN_LATER',
            self::BAD_GATEWAY => 'BAD_GATEWAY',
            self::TLS_HANDSHAKE_FAILURE => 'TLS_HANDSHAKE_FAILURE',
            default => null,
        };
    }

    /**
     * Returns true if the given code is expected to be sent by browsers when closing a connection.
     * Some applications may define other expected close codes, in which case this function may not apply.
     */
    public static function isExpected(int $code): bool
    {
        return match ($code) {
            WebsocketCloseCode::NORMAL_CLOSE, WebsocketCloseCode::GOING_AWAY, WebsocketCloseCode::NONE => true,
            default => false,
        };
    }

    /**
     * @codeCoverageIgnore Class cannot be instantiated.
     */
    private function __construct()
    {
        // no instances allowed
    }
}
