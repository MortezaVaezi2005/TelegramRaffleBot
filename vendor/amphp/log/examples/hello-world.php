<?php declare(strict_types=1);

use Amp\ByteStream;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Logger;

require dirname(__DIR__) . '/vendor/autoload.php';

$handler = new StreamHandler(ByteStream\getStdout());
$handler->setFormatter(new ConsoleFormatter());

$logger = new Logger('hello-world');
$logger->pushHandler($handler);

$logger->debug("Hello, world!");
$logger->info("Hello, world!");
$logger->notice("Hello, world!");
$logger->warning("Hello, world!");
$logger->error("Hello, world!");
$logger->critical("Hello, world!");
$logger->alert("Hello, world!");
$logger->emergency("Hello, world!");
