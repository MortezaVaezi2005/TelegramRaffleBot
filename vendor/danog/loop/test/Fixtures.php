<?php declare(strict_types=1);
/**
 * Loop test.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2020 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/MIT MIT
 */

namespace danog\Loop\Test;

use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

/**
 * Fixtures.
 */
abstract class Fixtures extends TestCase
{
    const LOOP_NAME = 'TTTT';
    protected static function waitTick(): void
    {
        $f = new \Amp\DeferredFuture;
        \Revolt\EventLoop::defer(fn () => $f->complete());
        $f->getFuture()->await();
    }
    protected function setUp(): void
    {
        EventLoop::run();
    }
    protected function tearDown(): void
    {
        EventLoop::run();
    }
}
