<?php declare(strict_types=1);

namespace Amp\Mysql\Internal;

use Amp\ByteStream\ResourceStream;
use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\File;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Future;
use Amp\Mysql\MysqlColumnDefinition;
use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlDataType;
use Amp\Mysql\MysqlResult;
use Amp\Parser\Parser;
use Amp\Socket\Socket;
use Amp\Sql\SqlConnectionException;
use Amp\Sql\SqlException;
use Amp\Sql\SqlQueryError;
use Amp\Sql\SqlTransientResource;
use Revolt\EventLoop;

/* @TODO
 * 14.2.3 Auth switch request??
 * 14.2.4 COM_CHANGE_USER
 */

/**
 * @internal
 * @see https://dev.mysql.com/doc/dev/mysql-server/latest/PAGE_PROTOCOL.html Protocol documentation.
 */
class ConnectionProcessor implements SqlTransientResource
{
    use ForbidCloning;
    use ForbidSerialization;

    private const COMPRESSION_MINIMUM_LENGTH = 860;
    private const MAX_PACKET_LENGTH = 0xffffff;

    const STATEMENT_PARAM_REGEX = <<<'REGEX'
        [
            # Skip all quoted groups.
            (['"])(?:\\(?:\\|\1)|(?!\1).)*+\1(*SKIP)(*FAIL)
            |
            # Unnamed parameters.
            (?<unnamed>
                # Match all question marks except those surrounded by "operator"-class characters on either side.
                (?<!(?<operators>[-+\\*/<>~!@#%^&|`?]))
                \?
                (?!\g<operators>|=)
                |
                :\?
            )
            |
            # Named parameters.
            (?<!:):(?<named>[a-zA-Z_][a-zA-Z0-9_]*)
        ]msxS
        REGEX;

    private Parser $parser;

    private int $seqId = -1;
    private int $compressionId = -1;

    private readonly Socket $socket;

    private ?string $query = null;
    private array $named = [];

    private ?\Closure $parseCallback = null;
    private ?\Closure $packetCallback = null;

    private MysqlConfig $config;

    private readonly MysqlConnectionMetadata $metadata;

    /** @var \SplQueue<DeferredFuture> */
    private readonly \SplQueue $deferreds;

    /** @var \SplQueue<\Closure():void> */
    private readonly \SplQueue $onReady;

    private ?MysqlResultProxy $result = null;

    private int $lastUsedAt;

    private int $connectionId = 0;
    private string $authPluginData = '';
    private int $capabilities = 0;
    private int $serverCapabilities = 0;
    private string $authPluginName = '';
    private int $refcount = 1;

    private ConnectionState $connectionState = ConnectionState::Unconnected;

    private ?DeferredFuture $paused = null;

    private const MAX_PACKET_SIZE = 0xffffff;
    private const MAX_UNCOMPRESSED_BUFLEN = 0xfffffb;

    private const CLIENT_LONG_FLAG = 0x00000004;
    private const CLIENT_CONNECT_WITH_DB = 0x00000008;
    private const CLIENT_COMPRESS = 0x00000020;
    private const CLIENT_LOCAL_INFILE = 0x00000080;
    private const CLIENT_PROTOCOL_41 = 0x00000200;
    private const CLIENT_SSL = 0x00000800;
    private const CLIENT_TRANSACTIONS = 0x00002000;
    private const CLIENT_SECURE_CONNECTION = 0x00008000;
    private const CLIENT_MULTI_STATEMENTS = 0x00010000;
    private const CLIENT_MULTI_RESULTS = 0x00020000;
    private const CLIENT_PS_MULTI_RESULTS = 0x00040000;
    private const CLIENT_PLUGIN_AUTH = 0x00080000;
    private const CLIENT_CONNECT_ATTRS = 0x00100000;
    private const CLIENT_SESSION_TRACK = 0x00800000;
    private const CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA = 0x00200000;
    private const CLIENT_DEPRECATE_EOF = 0x01000000;

    /* @see 14.1.3.4 Status Flags */
    private const SERVER_STATUS_IN_TRANS = 0x0001; // a transaction is active
    private const SERVER_STATUS_AUTOCOMMIT = 0x0002; // auto-commit is enabled
    private const SERVER_MORE_RESULTS_EXISTS = 0x0008;
    private const SERVER_STATUS_NO_GOOD_INDEX_USED = 0x0010;
    private const SERVER_STATUS_NO_INDEX_USED = 0x0020;
    private const SERVER_STATUS_CURSOR_EXISTS = 0x0040; // Used by Binary Protocol Resultset to signal that COM_STMT_FETCH has to be used to fetch the row-data.
    private const SERVER_STATUS_LAST_ROW_SENT = 0x0080;
    private const SERVER_STATUS_DB_DROPPED = 0x0100;
    private const SERVER_STATUS_NO_BACKSLASH_ESCAPES = 0x0200;
    private const SERVER_STATUS_METADATA_CHANGED = 0x0400;
    private const SERVER_QUERY_WAS_SLOW = 0x0800;
    private const SERVER_PS_OUT_PARAMS = 0x1000;
    private const SERVER_STATUS_IN_TRANS_READONLY = 0x2000; // in a read-only transaction
    private const SERVER_SESSION_STATE_CHANGED = 0x4000; // connection state information has changed

    private const OK_PACKET = 0x00;
    private const EXTRA_AUTH_PACKET = 0x01;
    private const LOCAL_INFILE_REQUEST = 0xfb;
    private const AUTH_SWITCH_PACKET = 0xfe;
    private const EOF_PACKET = 0xfe;
    private const ERR_PACKET = 0xff;

    public function __construct(Socket $socket, MysqlConfig $config)
    {
        $this->socket = $socket;
        $this->metadata = new MysqlConnectionMetadata();
        $this->config = $config;
        $this->lastUsedAt = \time();

        $this->deferreds = new \SplQueue();
        $this->onReady = new \SplQueue();

        $this->parser = new Parser($this->parseMysql());
    }

    public function isClosed(): bool
    {
        return match ($this->connectionState) {
            ConnectionState::Closing, ConnectionState::Closed => true,
            default => false,
        };
    }

    public function onClose(\Closure $onClose): void
    {
        $this->socket->onClose($onClose);
    }

    public function isReady(): bool
    {
        return $this->connectionState === ConnectionState::Ready;
    }

    public function unreference(): void
    {
        if (--$this->refcount || $this->isClosed()) {
            return;
        }

        $this->sendClose()->ignore();
    }

    private function ready(): void
    {
        if (!$this->deferreds->isEmpty()) {
            return;
        }

        if (!$this->onReady->isEmpty()) {
            $this->onReady->shift()();
            return;
        }

        $this->resetIds();
        if ($this->socket instanceof ResourceStream) {
            $this->socket->unreference();
        }
    }

    private function enqueueDeferred(DeferredFuture $deferred): void
    {
        \assert(!$this->socket->isClosed(), "The connection has been closed");
        $this->deferreds->push($deferred);
        if ($this->socket instanceof ResourceStream) {
            $this->socket->reference();
        }
    }

    public function connect(?Cancellation $cancellation = null): void
    {
        \assert(
            $this->connectionState === ConnectionState::Unconnected,
            self::class . "::connect() must not be called twice",
        );

        $this->connectionState = ConnectionState::Connecting;

        $this->enqueueDeferred($deferred = new DeferredFuture()); // Will be resolved in sendHandshake().

        $id = $cancellation?->subscribe($this->close(...));

        EventLoop::queue($this->read(...));

        $future = $deferred->getFuture();
        if ($id !== null) {
            $future = $future->finally(static fn () => $cancellation?->unsubscribe($id));
        }

        // if a charset is specified, we need to set before any query
        if ($this->config->getCharset() !== MysqlConfig::DEFAULT_CHARSET
            || $this->config->getCollation() !== MysqlConfig::DEFAULT_COLLATE
        ) {
            $future = $future->map(function (): void {
                $charset = $this->config->getCharset();
                $collate = $this->config->getCollation();

                $this->query("SET NAMES '$charset'" . ($collate === "" ? "" : " COLLATE '$collate'"))->await();
            });
        }

        if ($this->config->getSqlMode() !== null) {
            $future = $future->map(function (): void {
                $sqlMode = $this->config->getSqlMode();
                $this->query("SET SESSION sql_mode='$sqlMode'")->await();
            });
        }

        $future->await();
    }

    private function read(): void
    {
        try {
            while (($bytes = $this->socket->read()) !== null) {
                \assert($this->writeDebugData($bytes, 'in'));

                $this->lastUsedAt = \time();

                $this->parser->push($bytes);
                $bytes = null; // Free last data read.

                $this->paused?->getFuture()->await(); // Pause next read if negotiating TLS.
            }
        } catch (\Throwable $exception) {
            // $exception used as previous exception below.
        } finally {
            $this->free($exception ?? null);
        }
    }

    private function dequeueDeferred(): DeferredFuture
    {
        \assert(!$this->deferreds->isEmpty(), 'Pending deferred not found when shifting from pending queue');
        return $this->deferreds->shift();
    }

    /**
     * @param \Closure():void $callback
     */
    private function appendTask(\Closure $callback): void
    {
        if ($this->packetCallback
            || $this->parseCallback
            || !$this->onReady->isEmpty()
            || !$this->deferreds->isEmpty()
            || $this->connectionState !== ConnectionState::Ready
        ) {
            $this->onReady->push($callback);
        } else {
            $callback();
        }
    }

    public function getMetadata(): MysqlConnectionMetadata
    {
        return clone $this->metadata;
    }

    public function getConfig(): MysqlConfig
    {
        return $this->config;
    }

    public function getConnectionId(): int
    {
        return $this->connectionId;
    }

    public function getLastUsedAt(): int
    {
        return $this->lastUsedAt;
    }

    protected function startCommand(\Closure $callback): Future
    {
        if ($this->isClosed()) {
            throw new \Error("The connection has been closed");
        }

        $deferred = new DeferredFuture;
        $this->appendTask(function () use ($callback, $deferred) {
            $this->seqId = $this->compressionId = -1;
            $this->enqueueDeferred($deferred);
            $callback();
        });
        return $deferred->getFuture();
    }

    public function useCharacterSet(string $charset, string $collate): Future
    {
        if ($collate === "" && false !== $offset = \strpos($charset, "_")) {
            $collate = $charset;
            $charset = \substr($collate, 0, $offset);
        }

        $query = "SET NAMES '$charset'" . ($collate === "" ? "" : " COLLATE '$collate'");
        $future = $this->query($query);

        $this->config = $this->config->withCharset($charset, $collate);

        return $future;
    }

    /** @see 14.6.3 COM_INIT_DB */
    public function useDatabase(string $database): Future
    {
        return $this->startCommand(function () use ($database): void {
            /** @psalm-suppress PropertyTypeCoercion */
            $this->config = $this->config->withDatabase($database);
            $this->write("\x02$database");
        });
    }

    /**
     * @see 14.6.4 COM_QUERY
     *
     * @return Future<MysqlResult>
     */
    public function query(string $query): Future
    {
        return $this->startCommand(function () use ($query): void {
            $this->query = $query;
            $this->parseCallback = $this->handleQuery(...);
            $this->write("\x03$query");
        });
    }

    /**
     * @see 14.7.4 COM_STMT_PREPARE
     *
     * @return Future<MysqlConnectionStatement>
     */
    public function prepare(string $query): Future
    {
        return $this->startCommand(function () use ($query): void {
            $this->query = $query;
            $this->parseCallback = $this->handlePrepare(...);

            $query = \preg_replace_callback(self::STATEMENT_PARAM_REGEX, function (array $m): string {
                static $index = 0;
                if (isset($m['named'])) {
                    $this->named[$m['named']][] = $index;
                }
                $index++;
                return "?";
            }, $query);

            $this->write("\x16$query");
        });
    }

    /** @see 14.6.18 COM_CHANGE_USER */
    /* @TODO broken, my test server doesn't support that command, can't test now
    public function changeUser($user, $pass, $db = null) {
        return $this->startCommand(function() use ($user, $pass, $db) {
            $this->config->user = $user;
            $this->config->pass = $pass;
            $this->config->db = $db;
            $payload = "\x11";

            $payload .= "$user\0";
            $auth = $this->secureAuth($this->config->pass, $this->authPluginData);
            if ($this->capabilities & self::CLIENT_SECURE_CONNECTION) {
                $payload .= ord($auth) . $auth;
            } else {
                $payload .= "$auth\0";
            }
            $payload .= "$db\0";

            $this->write($payload);
            $this->parseCallback = [$this, "authSwitchRequest"];
        });
    }
    */

    /** @see 14.6.15 COM_PING */
    public function ping(): Future
    {
        return $this->startCommand(fn () => $this->write("\x0e"));
    }

    /** @see 14.6.19 COM_RESET_CONNECTION */
    public function resetConnection(): Future
    {
        return $this->startCommand(fn () => $this->write("\x1f"));
    }

    /** @see 14.7.5 COM_STMT_SEND_LONG_DATA */
    public function bindParam(int $stmtId, int $paramId, string $data): void
    {
        $payload = ["\x18"];
        $payload[] = MysqlDataType::encodeInt32($stmtId);
        $payload[] = MysqlDataType::encodeInt16($paramId);
        $payload[] = $data;
        $this->appendTask(function () use ($payload): void {
            $this->resetIds();
            $this->write(\implode($payload));
            $this->ready();
        });
    }

    /** @see 14.7.6 COM_STMT_EXECUTE
     * prebound params: null-bit set, type MYSQL_TYPE_LONG_BLOB, no value
     * $params is by-ref, because the actual result object might not yet have been filled completely with data upon
     * call of this method ...
     *
     * @param list<MysqlColumnDefinition> $params
     * @param array<int, string> $prebound
     * @param array<int, mixed> $data
     */
    public function execute(int $stmtId, string $query, array $params, array $prebound, array $data = []): Future
    {
        $deferred = new DeferredFuture;
        $this->appendTask(function () use ($stmtId, $query, $params, $prebound, $data, $deferred): void {
            $payload = ["\x17"];
            $payload[] = MysqlDataType::encodeInt32($stmtId);
            $payload[] = \chr(0); // cursor flag // @TODO cursor types?!
            $payload[] = MysqlDataType::encodeInt32(1);

            $paramCount = \count($params);
            $bound = (!empty($data) || !empty($prebound)) ? 1 : 0;
            $types = [];
            $values = [];

            if ($paramCount) {
                $args = $data + \array_fill(0, $paramCount, null);
                \ksort($args);
                $args = \array_slice($args, 0, $paramCount);
                $paramList = \str_repeat("\0", ($paramCount + 7) >> 3);
                foreach ($args as $paramId => $param) {
                    if ($param === null) {
                        $offset = ($paramId >> 3);
                        $paramList[$offset] = $paramList[$offset] | \chr(1 << ($paramId & 0x7));
                    } else {
                        $bound = 1;
                    }

                    $paramType = $params[$paramId]->getType();

                    if (isset($prebound[$paramId])) {
                        $types[] = MysqlDataType::encodeInt16(MysqlDataType::VarString->value);
                        continue;
                    }

                    $encodedValue = match ($paramType) {
                        MysqlDataType::Json => MysqlEncodedValue::fromJson((string) $param),
                        default => MysqlEncodedValue::fromValue($param),
                    };

                    $types[] = MysqlDataType::encodeInt16($encodedValue->getType()->value);
                    $values[] = $encodedValue->getBytes();
                }

                $payload[] = $paramList;
                $payload[] = \chr($bound);
                if ($bound) {
                    $payload[] = \implode($types);
                    $payload[] = \implode($values);
                }
            }

            $this->query = $query;

            $this->resetIds();
            $this->enqueueDeferred($deferred);
            $this->write(\implode($payload));
            // apparently LOAD DATA LOCAL INFILE requests are not supported via prepared statements
            $this->packetCallback = $this->handleExecute(...);
        });
        return $deferred->getFuture(); // do not use $this->startCommand(), that might unexpectedly reset the seqId!
    }

    /** @see 14.7.7 COM_STMT_CLOSE */
    public function closeStmt(int $stmtId): void
    {
        $payload = "\x19" . MysqlDataType::encodeInt32($stmtId);
        $this->appendTask(function () use ($payload): void {
            if ($this->connectionState === ConnectionState::Ready) {
                $this->resetIds();
                $this->write($payload);
                $this->resetIds(); // does not expect a reply - must be reset immediately
            }
            $this->ready();
        });
    }

    /** @see 14.6.5 COM_FIELD_LIST */
    public function listFields(string $table, string $like = "%"): Future
    {
        return $this->startCommand(function () use ($table, $like): void {
            $this->write("\x04$table\0$like");
            $this->parseCallback = $this->handleFieldList(...);
        });
    }

    public function listAllFields(string $table, string $like = "%"): Future
    {
        $map = function (?array $array) use (&$map): array {
            static $columns = [];

            if ($array === null) {
                return $columns;
            }

            [$columns[], $future] = $array;
            return $future->map($map)->await();
        };

        return $this->listFields($table, $like)->map($map);
    }

    /** @see 14.6.6 COM_CREATE_DB */
    public function createDatabase(string $db): Future
    {
        return $this->startCommand(fn () => $this->write("\x05$db"));
    }

    /** @see 14.6.7 COM_DROP_DB */
    public function dropDatabase(string $db): Future
    {
        return $this->startCommand(fn () => $this->write("\x06$db"));
    }

    /**
     * @see 14.6.8 COM_REFRESH
     */
    public function refresh(int $subcommand): Future
    {
        return $this->startCommand(fn () => $this->write("\x07" . \chr($subcommand)));
    }

    /** @see 14.6.9 COM_SHUTDOWN */
    public function shutdown(): Future
    {
        /* SHUTDOWN_DEFAULT / SHUTDOWN_WAIT_ALL_BUFFERS, only one in use */
        return $this->startCommand(fn () => $this->write("\x08\x00"));
    }

    /** @see 14.6.10 COM_STATISTICS */
    public function statistics(): Future
    {
        return $this->startCommand(function (): void {
            $this->write("\x09");
            $this->parseCallback = $this->readStatistics(...);
        });
    }

    /** @see 14.6.11 COM_PROCESS_INFO */
    public function processInfo(): Future
    {
        return $this->startCommand(function (): void {
            $this->write("\x0a");
            $this->query("SHOW PROCESSLIST");
        });
    }

    /** @see 14.6.13 COM_PROCESS_KILL */
    public function killProcess(int $process): Future
    {
        return $this->startCommand(fn () => $this->write("\x0c" . MysqlDataType::encodeInt32($process)));
    }

    /** @see 14.6.14 COM_DEBUG */
    public function debugStdout(): Future
    {
        return $this->startCommand(fn () => $this->write("\x0d"));
    }

    /** @see 14.7.8 COM_STMT_RESET */
    public function resetStmt(int $stmtId): Future
    {
        $payload = "\x1a" . MysqlDataType::encodeInt32($stmtId);
        $deferred = new DeferredFuture;
        $this->appendTask(function () use ($payload, $deferred): void {
            $this->resetIds();
            $this->enqueueDeferred($deferred);
            $this->write($payload);
        });
        return $deferred->getFuture();
    }

    /** @see 14.8.4 COM_STMT_FETCH */
    public function fetchStmt(int $stmtId): Future
    {
        $payload = "\x1c" . MysqlDataType::encodeInt32($stmtId) . MysqlDataType::encodeInt32(1);
        $deferred = new DeferredFuture;
        $this->appendTask(function () use ($payload, $deferred): void {
            $this->resetIds();
            $this->enqueueDeferred($deferred);
            $this->write($payload);
        });
        return $deferred->getFuture();
    }

    private function established(): void
    {
        // @TODO flags to use?
        $this->capabilities |= self::CLIENT_SESSION_TRACK
            | self::CLIENT_TRANSACTIONS
            | self::CLIENT_PROTOCOL_41
            | self::CLIENT_SECURE_CONNECTION
            | self::CLIENT_MULTI_RESULTS
            | self::CLIENT_PS_MULTI_RESULTS
            | self::CLIENT_MULTI_STATEMENTS
            | self::CLIENT_PLUGIN_AUTH
            | self::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA;

        if (\extension_loaded("zlib") && $this->config->isCompressionEnabled()) {
            $this->capabilities |= self::CLIENT_COMPRESS;
        }

        if ($this->config->isLocalInfileEnabled()) {
            $this->capabilities |=  self::CLIENT_LOCAL_INFILE;
        }
    }

    /** @see 14.1.3.2 ERR-Packet */
    private function handleError(string $packet): void
    {
        $offset = 1;

        $this->metadata->errorCode = MysqlDataType::decodeUnsigned16($packet, $offset);

        $connecting = $this->connectionState === ConnectionState::Connecting
            || $this->connectionState === ConnectionState::Established;

        if ($this->capabilities & self::CLIENT_PROTOCOL_41 && !$connecting) {
            $this->metadata->errorState = \substr($packet, $offset, 6);
            $offset += 6;
        }

        $this->metadata->errorMsg = \substr($packet, $offset);

        $this->parseCallback = null;

        if ($connecting) {
            // connection failure
            $this->free(new SqlConnectionException(\sprintf(
                'Could not connect to %s: %s',
                $this->config->getConnectionString(),
                $this->metadata->errorMsg,
            )));
            return;
        }

        if ($this->result === null && $this->deferreds->isEmpty()) {
            // connection killed without pending query or active result
            $this->free(new SqlConnectionException('Connection closed after receiving an unexpected error packet'));
            return;
        }

        $deferred = $this->result ?? $this->dequeueDeferred();

        // normal error
        $exception = new SqlQueryError(\sprintf(
            'MySQL error (%d): %s %s',
            $this->metadata->errorCode,
            $this->metadata->errorState ?? 'Unknown state',
            $this->metadata->errorMsg,
        ), $this->query ?? '');

        $this->result = null;
        $this->query = null;
        $this->named = [];

        $deferred->error($exception);

        $this->ready();
    }

    /**
     * @see 14.1.3.1 OK-Packet
     *
     * @psalm-suppress TypeDoesNotContainType Psalm seems to be having trouble with enums.
     */
    private function parseOk(string $packet): void
    {
        $offset = 1;

        $this->metadata->affectedRows = MysqlDataType::decodeUnsigned($packet, $offset);
        $this->metadata->insertId = MysqlDataType::decodeUnsigned($packet, $offset);

        if ($this->capabilities & (self::CLIENT_PROTOCOL_41 | self::CLIENT_TRANSACTIONS)) {
            $this->metadata->statusFlags = MysqlDataType::decodeUnsigned16($packet, $offset);
            $this->metadata->warnings = MysqlDataType::decodeUnsigned16($packet, $offset);
        }

        if (!($this->capabilities & self::CLIENT_SESSION_TRACK)) {
            $this->metadata->statusInfo = \substr($packet, $offset);
            return;
        }

        // Even though it seems required according to 14.1.3.1, there is no length encoded string,
        // i.e. no trailing NULL byte ....???
        if (\strlen($packet) <= $offset) {
            $this->metadata->statusInfo = "";
            return;
        }

        $this->metadata->statusInfo = MysqlDataType::decodeString($packet, $offset);

        if (!($this->metadata->statusFlags & self::SERVER_SESSION_STATE_CHANGED)) {
            return;
        }

        $sessionState = MysqlDataType::decodeString($packet, $offset);

        while (\strlen($packet) > $offset) {
            $data = MysqlDataType::decodeString($sessionState, $offset);
            $type = MysqlDataType::decodeUnsigned8($sessionState, $offset);

            switch (SessionStateType::tryFrom($type)) {
                case SessionStateType::SystemVariables:
                    $var = MysqlDataType::decodeString($data, $offset);
                    $this->metadata->sessionState[$type][$var] = MysqlDataType::decodeString($data, $offset);
                    break;

                case SessionStateType::Schema:
                case SessionStateType::StateChange:
                case SessionStateType::Gtids:
                case SessionStateType::TransactionCharacteristics:
                case SessionStateType::TransactionState:
                    $this->metadata->sessionState[$type] = MysqlDataType::decodeString($data, $offset);
                    break;

                default:
                    throw new \Error("$type is not a valid mysql session state type");
            }
        }
    }

    private function handleOk(string $packet): void
    {
        $this->parseOk($packet);
        $this->dequeueDeferred()->complete(
            new MysqlCommandResult($this->metadata->affectedRows, $this->metadata->insertId),
        );
        $this->ready();
    }

    /** @see 14.1.3.3 EOF-Packet */
    private function parseEof(string $packet): void
    {
        $offset = 1;
        if ($this->capabilities & self::CLIENT_PROTOCOL_41) {
            $this->metadata->warnings = MysqlDataType::decodeUnsigned16($packet, $offset);
            $this->metadata->statusFlags = MysqlDataType::decodeUnsigned16($packet, $offset);
        }
    }

    private function handleEof(string $packet): void
    {
        $this->parseEof($packet);
        $exception = new SqlException($this->metadata->errorMsg ?? 'Unknown error', $this->metadata->errorCode ?? 0);
        $this->dequeueDeferred()->error($exception);
        $this->ready();
    }

    /** @see 14.2.5 Connection Phase Packets */
    private function handleHandshake(string $packet): void
    {
        $offset = 1;

        $protocol = \ord($packet);

        if ($protocol === self::ERR_PACKET) {
            $this->handleError($packet);
            return;
        }

        if ($protocol !== 0x0a) {
            throw new SqlConnectionException("Unsupported protocol version ".\ord($packet)." (Expected: 10)");
        }

        $this->metadata->serverVersion = MysqlDataType::decodeNullTerminatedString($packet, $offset);

        $this->connectionId = MysqlDataType::decodeUnsigned32($packet, $offset);

        $this->authPluginData = \substr($packet, $offset, 8);
        $offset += 8;

        $offset += 1; // filler byte

        $this->serverCapabilities = MysqlDataType::decodeUnsigned16($packet, $offset);

        if (\strlen($packet) > $offset) {
            $this->metadata->charset = MysqlDataType::decodeUnsigned8($packet, $offset);
            $this->metadata->statusFlags = MysqlDataType::decodeUnsigned16($packet, $offset);
            $this->serverCapabilities += MysqlDataType::decodeUnsigned16($packet, $offset) << 16;

            $authPluginDataLen = $this->serverCapabilities & self::CLIENT_PLUGIN_AUTH
                ? MysqlDataType::decodeUnsigned8($packet, $offset)
                : 0;

            if ($this->serverCapabilities & self::CLIENT_SECURE_CONNECTION) {
                $offset += 10;

                $strlen = \max(13, $authPluginDataLen - 8);
                $this->authPluginData .= \substr($packet, $offset, $strlen);
                $offset += $strlen;

                if ($this->serverCapabilities & self::CLIENT_PLUGIN_AUTH) {
                    $this->authPluginName = MysqlDataType::decodeNullTerminatedString($packet, $offset);
                }
            }
        }

        $this->sendHandshake();
    }

    /** @see 14.2.5 Connection Phase Packets */
    private function handleAuthSwitch(string $packet): void
    {
        $offset = 1;
        $this->authPluginName = MysqlDataType::decodeNullTerminatedString($packet, $offset);
        $this->authPluginData = \substr($packet, $offset);
        $this->sendAuthSwitchResponse();
    }

    private function sendAuthSwitchResponse(): void
    {
        $this->write($this->getAuthData());
    }

    /** @see 14.6.4.1.2 LOCAL INFILE Request */
    private function handleLocalInfileRequest(string $packet): void
    {
        EventLoop::queue(function () use ($packet): void {
            try {
                $filePath = \substr($packet, 1);
                /** @var \Amp\File\File $fileHandle */
                if (!\function_exists("Amp\\File\\openFile")) {
                    throw new \Error("amphp/file must be installed for LOCAL INFILE queries");
                }

                $fileHandle = File\openFile($filePath, 'r');

                while (null !== ($chunk = $fileHandle->read())) {
                    $this->write($chunk);
                }
                $this->write("");
            } catch (\Throwable $e) {
                $this->dequeueDeferred()->error(new SqlConnectionException("Failed to transfer a file to the server", 0, $e));
            }
        });
    }

    /** @see 14.6.4.1.1 Text Resultset */
    private function handleQuery(string $packet): void
    {
        switch (\ord($packet)) {
            case self::OK_PACKET:
                $this->parseOk($packet);

                if ($this->metadata->statusFlags & self::SERVER_MORE_RESULTS_EXISTS) {
                    $this->result = new MysqlResultProxy(
                        affectedRows: $this->metadata->affectedRows,
                        insertId: $this->metadata->insertId
                    );
                    $this->result->markDefinitionsFetched();
                    $this->dequeueDeferred()->complete(new MysqlConnectionResult($this->result));
                    $this->successfulResultFetch();
                } else {
                    $this->parseCallback = null;
                    $this->dequeueDeferred()->complete(new MysqlCommandResult(
                        $this->metadata->affectedRows,
                        $this->metadata->insertId
                    ));
                    $this->ready();
                }
                return;
            case self::LOCAL_INFILE_REQUEST:
                if ($this->config->isLocalInfileEnabled()) {
                    $this->handleLocalInfileRequest($packet);
                } else {
                    $this->dequeueDeferred()->error(new SqlConnectionException("Unexpected LOCAL_INFILE_REQUEST packet"));
                }
                return;
            case self::ERR_PACKET:
                $this->handleError($packet);
                return;
        }

        $this->parseCallback = $this->handleTextColumnDefinition(...);
        $this->result = new MysqlResultProxy(MysqlDataType::decodeUnsigned($packet));
        $this->dequeueDeferred()->complete(new MysqlConnectionResult($this->result));
    }

    /** @see 14.7.1 Binary Protocol Resultset */
    private function handleExecute(string $packet): void
    {
        $this->parseCallback = $this->handleBinaryColumnDefinition(...);
        $this->result = new MysqlResultProxy(\ord($packet));
        $this->dequeueDeferred()->complete(new MysqlConnectionResult($this->result));
    }

    private function handleFieldList(string $packet): void
    {
        if (\ord($packet) === self::ERR_PACKET) {
            $this->parseCallback = null;
            $this->handleError($packet);
        } elseif (\ord($packet) === self::EOF_PACKET) {
            $this->parseCallback = null;
            $this->parseEof($packet);
            $this->dequeueDeferred()->complete();
            $this->ready();
        } else {
            $this->enqueueDeferred($deferred = new DeferredFuture);
            $this->dequeueDeferred()->complete([$this->parseColumnDefinition($packet), $deferred]);
        }
    }

    private function handleTextColumnDefinition(string $packet): void
    {
        $this->handleColumnDefinition($packet, $this->handleTextResultSetRow(...));
    }

    private function handleBinaryColumnDefinition(string $packet): void
    {
        $this->handleColumnDefinition($packet, $this->handleBinaryResultSetRow(...));
    }

    private function handleColumnDefinition(string $packet, \Closure $parseCallback): void
    {
        \assert($this->result !== null, 'Connection result was in invalid state');

        if (!$this->result->columnsToFetch--) {
            $this->result->markDefinitionsFetched();
            if (\ord($packet) === self::ERR_PACKET) {
                $this->parseCallback = null;
                $this->handleError($packet);
            } else {
                $cb = $this->parseCallback = $parseCallback;
                if ($this->capabilities & self::CLIENT_DEPRECATE_EOF) {
                    $cb($packet);
                } else {
                    $this->parseEof($packet);
                    // we don't need the EOF packet, skip!
                }
            }
            return;
        }

        $this->result->columns[] = $this->parseColumnDefinition($packet);
    }

    private function prepareParams(string $packet): void
    {
        \assert($this->result !== null, 'Connection result was in invalid state');

        if (!$this->result->columnsToFetch--) {
            $this->result->columnsToFetch = $this->result->columnCount;
            if (!$this->result->columnsToFetch) {
                $this->prepareFields($packet);
            } else {
                $this->parseCallback = $this->prepareFields(...);
            }
            return;
        }

        $this->result->params[] = $this->parseColumnDefinition($packet);
    }

    private function prepareFields(string $packet): void
    {
        \assert($this->result !== null, 'Connection result was in invalid state');

        if (!$this->result->columnsToFetch--) {
            $this->parseCallback = null;
            $this->query = null;
            $result = $this->result;
            $this->result = null;
            $this->ready();
            $result->markDefinitionsFetched();

            return;
        }

        $this->result->columns[] = $this->parseColumnDefinition($packet);
    }

    /** @see 14.6.4.1.1.2 Column Defintion */
    private function parseColumnDefinition(string $packet): MysqlColumnDefinition
    {
        $offset = 0;
        $column = [];

        if ($this->capabilities & self::CLIENT_PROTOCOL_41) {
            $column["catalog"] = MysqlDataType::decodeString($packet, $offset);
            $column["schema"] = MysqlDataType::decodeString($packet, $offset);
            $column["table"] = MysqlDataType::decodeString($packet, $offset);
            $column["originalTable"] = MysqlDataType::decodeString($packet, $offset);
            $column["name"] = MysqlDataType::decodeString($packet, $offset);
            $column["originalName"] = MysqlDataType::decodeString($packet, $offset);
            $fixLength = MysqlDataType::decodeUnsigned($packet, $offset);

            $column["charset"] = MysqlDataType::decodeUnsigned16($packet, $offset);
            $column["length"] = MysqlDataType::decodeUnsigned32($packet, $offset);
            $column["type"] = MysqlDataType::from(MysqlDataType::decodeUnsigned8($packet, $offset));
            $column["flags"] = MysqlDataType::decodeUnsigned16($packet, $offset);
            $column["decimals"] = MysqlDataType::decodeUnsigned8($packet, $offset);

            $offset += $fixLength;
        } else {
            $column["table"] = MysqlDataType::decodeString($packet, $offset);
            $column["name"] = MysqlDataType::decodeString($packet, $offset);

            $columnLength = MysqlDataType::decodeUnsigned($packet, $offset);
            $column["length"] = MysqlDataType::decodeIntByLength($packet, $columnLength, $offset);

            $typeLength = MysqlDataType::decodeUnsigned($packet, $offset);
            $column["type"] = MysqlDataType::from(MysqlDataType::decodeIntByLength($packet, $typeLength, $offset));

            $flagLength = $this->capabilities & self::CLIENT_LONG_FLAG
                ? MysqlDataType::decodeUnsigned($packet, $offset)
                : MysqlDataType::decodeUnsigned8($packet, $offset);

            if ($flagLength > 2) {
                $column["flags"] = MysqlDataType::decodeUnsigned16($packet, $offset);
            } else {
                $column["flags"] = MysqlDataType::decodeUnsigned8($packet, $offset);
            }

            $column["decimals"] = MysqlDataType::decodeUnsigned8($packet, $offset);
        }

        if ($offset < \strlen($packet)) {
            $column["defaults"] = MysqlDataType::decodeString($packet, $offset);
        }

        /** @psalm-suppress InvalidScalarArgument, ArgumentTypeCoercion */
        return new MysqlColumnDefinition(...$column);
    }

    private function successfulResultFetch(): void
    {
        \assert($this->result !== null, 'Connection result was in invalid state');

        $deferred = $this->result->next ??= new DeferredFuture();

        if ($this->metadata->statusFlags & self::SERVER_MORE_RESULTS_EXISTS) {
            $this->parseCallback = $this->handleQuery(...);
            $this->enqueueDeferred($deferred);
        } else {
            $this->parseCallback = null;
            $this->query = null;
            $deferred->complete();
            $this->ready();
        }

        $this->result->complete();
        $this->result = null;
    }

    /** @see 14.6.4.1.1.3 Resultset Row */
    private function handleTextResultSetRow(string $packet): void
    {
        $packetType = \ord($packet);
        if ($packetType === self::EOF_PACKET) {
            if ($this->capabilities & self::CLIENT_DEPRECATE_EOF) {
                $this->parseOk($packet);
            } else {
                $this->parseEof($packet);
            }
            $this->successfulResultFetch();
            return;
        }

        if ($packetType === self::ERR_PACKET) {
            $this->handleError($packet);
            return;
        }

        \assert($this->result !== null, 'Connection result was in invalid state');

        $offset = 0;
        $fields = [];
        for ($i = 0; $offset < \strlen($packet); ++$i) {
            if (\ord($packet[$offset]) === 0xfb) {
                $fields[] = null;
                $offset += 1;
            } else {
                $column = $this->result->columns[$i] ?? throw new \RuntimeException("Definition missing for column $i");
                $fields[] = $column->getType()->decodeText($packet, $offset, $column->getFlags());
            }
        }

        $this->result->pushRow($fields);
    }

    /** @see 14.7.2 Binary Protocol Resultset Row */
    private function handleBinaryResultSetRow(string $packet): void
    {
        $packetType = \ord($packet);
        if ($packetType === self::EOF_PACKET) {
            $this->parseEof($packet);
            $this->successfulResultFetch();
            return;
        }

        if ($packetType === self::ERR_PACKET) {
            $this->handleError($packet);
            return;
        }

        \assert($this->result !== null, 'Connection result was in invalid state');

        $offset = 1; // skip first byte
        $offset += ($this->result->columnCount + 9) >> 3;
        $fields = [];

        for ($i = 0; $i < $this->result->columnCount; $i++) {
            if (\ord($packet[1 + (($i + 2) >> 3)]) & (1 << (($i + 2) % 8))) {
                $fields[] = null;
                continue;
            }

            $column = $this->result->columns[$i] ?? throw new \RuntimeException("Definition missing for column $i");
            \assert($offset >= 0 && $offset < \strlen($packet));
            $fields[] = $column->getType()->decodeBinary($packet, $offset, $column->getFlags());
        }

        $this->result->pushRow($fields);
    }

    /** @see 14.7.4.1 COM_STMT_PREPARE Response */
    private function handlePrepare(string $packet): void
    {
        switch (\ord($packet)) {
            case self::OK_PACKET:
                break;
            case self::ERR_PACKET:
                $this->handleError($packet);
                return;
            default:
                throw new SqlConnectionException("Unexpected value for first byte of COM_STMT_PREPARE Response");
        }

        $offset = 1;

        $stmtId = MysqlDataType::decodeUnsigned32($packet, $offset);
        $columns = MysqlDataType::decodeUnsigned16($packet, $offset);
        $params = MysqlDataType::decodeUnsigned16($packet, $offset);

        $offset += 1; // filler

        $this->metadata->warnings = MysqlDataType::decodeUnsigned16($packet, $offset);

        $this->result = new MysqlResultProxy($columns, $params);
        $this->refcount++;
        \assert($this->query !== null, 'Invalid value for connection query');
        $this->dequeueDeferred()->complete(new MysqlConnectionStatement($this, $this->query, $stmtId, $this->named, $this->result));
        $this->named = [];
        if ($params) {
            $this->parseCallback = $this->prepareParams(...);
        } else {
            $this->prepareParams($packet);
        }
    }

    private function readStatistics(string $packet): void
    {
        $this->dequeueDeferred()->complete($packet);
        $this->parseCallback = null;
        $this->ready();
    }

    /** @see 14.6.2 COM_QUIT */
    public function sendClose(): Future
    {
        return $this->startCommand(function (): void {
            $this->write("\x01");
            $this->connectionState = ConnectionState::Closing;
        });
    }

    public function close(): void
    {
        $this->free();
    }

    private function free(?\Throwable $exception = null): void
    {
        if ($this->connectionState === ConnectionState::Closing) {
            \assert(!$this->deferreds->isEmpty(), 'Closing deferred not found in array when in closing state');
            $this->deferreds->pop()->complete();
        }

        $this->connectionState = ConnectionState::Closed;

        $this->socket->close();
        $this->parser->cancel();

        if (!$this->deferreds->isEmpty() || $this->result) {
            if (!$exception instanceof SqlConnectionException) {
                $exception = new SqlConnectionException("Connection closed unexpectedly", 0, $exception ?? null);
            }

            while (!$this->deferreds->isEmpty()) {
                $this->deferreds->shift()->error($exception);
            }

            $this->result?->error($exception);
            $this->result = null;
        }
    }

    private function resetIds(): void
    {
        $this->seqId = $this->compressionId = -1;
    }

    private function write(string $packet): void
    {
        \assert(!$this->socket->isClosed(), 'The connection was closed during a call to write');

        while (\strlen($packet) >= self::MAX_PACKET_LENGTH) {
            $this->sendPacket(\substr($packet, 0, self::MAX_PACKET_LENGTH));
            $packet = \substr($packet, self::MAX_PACKET_LENGTH);
        }

        $this->sendPacket($packet);
    }

    /**
     * @codeCoverageIgnore
     */
    private function writeDebugData(string $packet, string $label): bool
    {
        if (\defined("MYSQL_DEBUG")) {
            \fwrite(STDERR, "$label: ");
            for ($i = 0; $i < \min(\strlen($packet), 200); $i++) {
                \fwrite(STDERR, \dechex(\ord($packet[$i])) . " ");
            }
            $r = \range("\0", "\x1f");
            unset($r[10], $r[9]);
            \fwrite(STDERR, "len: ".\strlen($packet)."\n");
            \fwrite(STDERR, \str_replace($r, ".", \substr($packet, 0, 200))."\n");
        }

        return true;
    }

    /**
     * @see 14.1.2 MySQL Packets
     */
    private function sendPacket(string $out): void
    {
        $packet = MysqlDataType::encodeInt32(\strlen($out) | (++$this->seqId << 24)) . $out;

        \assert($this->writeDebugData($packet, 'out'));

        if (($this->capabilities & self::CLIENT_COMPRESS) && $this->connectionState === ConnectionState::Ready) {
            $packet = $this->compressPacket($packet);
        }

        $this->socket->write($packet);
    }

    /**
     * @see 14.4 Compression
     */
    private function compressPacket(string $packet): string
    {
        $length = \strlen($packet);
        if ($length < self::COMPRESSION_MINIMUM_LENGTH) {
            return $this->makeCompressedPacket(0, $packet);
        }

        $deflated = \zlib_encode($packet, \ZLIB_ENCODING_DEFLATE);
        if ($length < \strlen($deflated)) {
            return $this->makeCompressedPacket(0, $packet);
        }

        return $this->makeCompressedPacket($length, $deflated);
    }

    private function makeCompressedPacket(int $uncompressed, string $packet): string
    {
        return MysqlDataType::encodeInt32(\strlen($packet) | (++$this->compressionId << 24))
            . MysqlDataType::encodeInt24($uncompressed) . $packet;
    }

    /**
     * @see 14.4 Compression
     *
     * @return \Generator<int, int, string, void>
     *
     * @psalm-suppress InvalidReturnType Psalm confuses this function to have a return of never
     */
    private function parseCompression(Parser $parser): \Generator
    {
        while (true) {
            $buffer = yield 7;
            $offset = 0;

            $length = MysqlDataType::decodeUnsigned24($buffer, $offset);
            $this->compressionId = MysqlDataType::decodeUnsigned8($buffer, $offset);
            $uncompressed = MysqlDataType::decodeUnsigned24($buffer, $offset);

            if ($length > 0) {
                $buffer = yield $length;

                if ($uncompressed !== 0) {
                    $buffer = \zlib_decode($buffer, $uncompressed);
                    if ($buffer === false) {
                        throw new \RuntimeException('Decompression failed');
                    }
                }

                $parser->push($buffer);
            }
        }
    }

    /**
     * @see 14.1.2 MySQL Packet
     * @see 14.1.3 Generic Response Packets
     *
     * @return \Generator<int, int, string, void>
     */
    private function parseMysql(): \Generator
    {
        while (true) {
            $packet = '';

            do {
                $buffer = yield 4;
                $offset = 0;

                $length = MysqlDataType::decodeUnsigned24($buffer, $offset);
                $this->seqId = MysqlDataType::decodeUnsigned8($buffer, $offset);

                if ($length > 0) {
                    $packet .= yield $length;
                }
            } while ($length === self::MAX_PACKET_LENGTH);

            if ($packet !== '') {
                $this->parsePayload($packet);
            }
        }
    }

    private function parsePayload(string $packet): void
    {
        if ($this->connectionState === ConnectionState::Connecting) {
            $this->established();
            $this->connectionState = ConnectionState::Established;
            $this->handleHandshake($packet);
            return;
        }

        if ($this->connectionState === ConnectionState::Established) {
            switch (\ord($packet)) {
                case self::OK_PACKET:
                    if ($this->capabilities & self::CLIENT_COMPRESS) {
                        $this->parser = new Parser($this->parseCompression($this->parser));
                    }
                    $this->connectionState = ConnectionState::Ready;
                    $this->handleOk($packet);
                    break;
                case self::ERR_PACKET:
                    $this->handleError($packet);
                    break;
                case self::AUTH_SWITCH_PACKET:
                    $this->handleAuthSwitch($packet);
                    break;
                case self::EXTRA_AUTH_PACKET:
                    /** @see 14.2.5 Connection Phase Packets (AuthMoreData) */
                    switch ($this->authPluginName) {
                        case "sha256_password":
                            $key = \substr($packet, 1);
                            $this->config = $this->config->withKey($key);
                            $this->sendHandshake();
                            break;
                        case "caching_sha2_password":
                            switch (\ord(\substr($packet, 1, 1))) {
                                case 3: // success
                                    return; // expecting OK afterwards
                                case 4: // fast auth failure
                                    /* unix domain socket, information not trivially available from $this->socket */
                                    if ($this->capabilities & self::CLIENT_SSL || $this->config->getHost()[0] === "/") {
                                        $this->write($this->config->getPassword() . "\0");
                                    } else {
                                        $this->write("\x02");
                                    }
                                    break;
                                case 0x2d: // certificate
                                    $pubkey = \substr($packet, 1);
                                    $this->write($this->sha256Auth($this->config->getPassword() ?? '', $this->authPluginData, $pubkey));
                                    break;
                            }
                            break;
                        default:
                            throw new SqlConnectionException(
                                "Unexpected EXTRA_AUTH_PACKET in authentication phase for method {$this->authPluginName}"
                            );
                    }
                    break;
            }
            return;
        }

        if ($this->parseCallback) {
            ($this->parseCallback)($packet);
            return;
        }

        $cb = $this->packetCallback;
        $this->packetCallback = null;
        switch (\ord($packet)) {
            case self::OK_PACKET:
                $this->handleOk($packet);
                break;
            case self::ERR_PACKET:
                $this->handleError($packet);
                break;
            case self::EOF_PACKET:
                if (\strlen($packet) < 6) {
                    $this->handleEof($packet);
                    break;
                }
                // no break
            default:
                if (!$cb) {
                    throw new SqlConnectionException("Unexpected packet type: " . \ord($packet));
                }

                $cb($packet);
        }
    }

    private function secureAuth(string $pass, string $scramble): string
    {
        $hash = \sha1($pass, true);
        return $hash ^ \sha1(\substr($scramble, 0, 20) . \sha1($hash, true), true);
    }

    private function sha256Auth(string $pass, string $scramble, string $key): string
    {
        \openssl_public_encrypt(
            "$pass\0" ^ \str_repeat($scramble, (int) \ceil(\strlen($pass) / \strlen($scramble))),
            $auth,
            PublicKeyCache::loadKey($key),
            \OPENSSL_PKCS1_OAEP_PADDING,
        );

        return $auth;
    }

    public static function sha2Auth(string $pass, string $scramble): string
    {
        $digestStage1 = \hash("sha256", $pass, true);
        $digestStage2 = \hash("sha256", $digestStage1, true);
        $scrambleStage1 = \hash("sha256", $digestStage2 . \substr($scramble, 0, 20), true);
        return $digestStage1 ^ $scrambleStage1;
    }

    private function authSwitchRequest(string $packet): void
    {
        $this->parseCallback = null;
        switch (\ord($packet)) {
            case self::EOF_PACKET:
                if (\strlen($packet) === 1) {
                    break;
                }
                $length = (int) \strpos($packet, "\0");
                $pluginName = \substr($packet, 0, $length); // @TODO mysql_native_pass only now...
                $authPluginData = \substr($packet, $length + 1);
                $this->write($this->secureAuth($this->config->getPassword() ?? '', $authPluginData));
                break;
            case self::ERR_PACKET:
                $this->handleError($packet);
                return;
            default:
                throw new SqlConnectionException("AuthSwitchRequest: Expecting 0xfe (or ERR_Packet), got 0x".\dechex(\ord($packet)));
        }
    }

    /**
     * @see 14.2.5 Connection Phase Packets
     * @see 14.3 Authentication Method
     */
    private function sendHandshake(): void
    {
        if ($this->config->getDatabase() !== null) {
            $this->capabilities |= self::CLIENT_CONNECT_WITH_DB;
        }

        if ($this->config->getConnectContext()->getTlsContext() !== null) {
            $this->capabilities |= self::CLIENT_SSL;
        }

        $this->capabilities &= $this->serverCapabilities;

        $tlsEnabled = false;

        do {
            $payload = [];
            $payload[] = \pack("V", $this->capabilities);
            $payload[] = \pack("V", self::MAX_PACKET_LENGTH);
            $payload[] = \chr(MysqlConfig::BIN_CHARSET);
            $payload[] = \str_repeat("\0", 23); // reserved

            if ($tlsEnabled || !($this->capabilities & self::CLIENT_SSL)) {
                break;
            }

            $paused = $this->paused = new DeferredFuture;

            try {
                $this->write(\implode($payload));
                $this->socket->setupTls();
                $tlsEnabled = true;
            } catch (\Throwable $e) {
                $this->free($e);
                return;
            } finally {
                $paused->complete();
                $this->paused = null;
            }
        } while (true);

        $payload[] = $this->config->getUser()."\0";

        $auth = $this->getAuthData();
        if ($this->capabilities & self::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA) {
            $payload[] = MysqlDataType::encodeInt(\strlen($auth));
            $payload[] = $auth;
        } elseif ($this->capabilities & self::CLIENT_SECURE_CONNECTION) {
            $payload[] = \chr(\strlen($auth));
            $payload[] = $auth;
        } else {
            $payload[] = "$auth\0";
        }

        if ($this->capabilities & self::CLIENT_CONNECT_WITH_DB) {
            $payload[] = "{$this->config->getDatabase()}\0";
        }

        if ($this->capabilities & self::CLIENT_PLUGIN_AUTH) {
            $payload[] = "{$this->authPluginName}\0";
        }

        if ($this->capabilities & self::CLIENT_CONNECT_ATTRS) {
            // connection attributes?! 5.6.6+ only!
        }

        $this->write(\implode($payload));
    }

    private function getAuthData(): string
    {
        $password = $this->config->getPassword() ?? "";

        if ($this->config->getPassword() == "") {
            return "";
        }

        if ($this->capabilities & self::CLIENT_PLUGIN_AUTH) {
            switch ($this->authPluginName) {
                case "mysql_native_password":
                    return $this->secureAuth($password, $this->authPluginData);
                case "mysql_clear_password":
                    return $password;
                case "sha256_password":
                    $key = $this->config->getKey();
                    if ($key !== '') {
                        return $this->sha256Auth($password, $this->authPluginData, $key);
                    }
                    return "\x01";
                case "caching_sha2_password":
                    return self::sha2Auth($password, $this->authPluginData);
                case "mysql_old_password":
                    throw new SqlConnectionException(
                        "mysql_old_password is outdated and insecure. Intentionally not implemented!"
                    );
                default:
                    throw new SqlConnectionException(
                        "Invalid (or unimplemented?) auth method requested by server: {$this->authPluginName}"
                    );
            }
        }

        return $this->secureAuth($password, $this->authPluginData);
    }
}
