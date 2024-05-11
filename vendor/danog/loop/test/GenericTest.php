<?php declare(strict_types=1);
/**
 * Loop test.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2020 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/MIT MIT
 */

namespace danog\Loop\Test;

use danog\Loop\GenericLoop;
use danog\Loop\Loop;
use danog\Loop\Test\Interfaces\LoggingPauseInterface;
use danog\Loop\Test\Traits\Basic;
use danog\Loop\Test\Traits\LoggingPause;
use Revolt\EventLoop;

use function Amp\delay;

class GenericTest extends Fixtures
{
    /**
     * Test basic loop.
     *
     * @param bool $stopSig Whether to stop with signal
     *
     *
     *
     * @dataProvider provideTrueFalse
     */
    public function testGeneric(bool $stopSig): void
    {
        $runCount = 0;
        $pauseTime = GenericLoop::PAUSE;
        $callable = function (GenericLoop $genericLoop) use (&$runCount, &$pauseTime, &$l) {
            $l = $genericLoop;
            $runCount++;
            return $pauseTime;
        };
        $this->fixtureAssertions($callable, $runCount, $pauseTime, $stopSig, $l);
        $obj = new class() {
            public $pauseTime = GenericLoop::PAUSE;
            public $runCount = 0;
            public ?GenericLoop $loop;
            public function run(GenericLoop $loop)
            {
                $this->loop = $loop;
                $this->runCount++;
                return $this->pauseTime;
            }
        };
        $this->fixtureAssertions([$obj, 'run'], $obj->runCount, $obj->pauseTime, $stopSig, $obj->loop);
        $obj = new class() {
            public $pauseTime = GenericLoop::PAUSE;
            public $runCount = 0;
            public ?GenericLoop $loop;
            public function run(GenericLoop $loop)
            {
                $this->loop = $loop;
                $this->runCount++;
                return $this->pauseTime;
            }
        };
        $this->fixtureAssertions(\Closure::fromCallable([$obj, 'run']), $obj->runCount, $obj->pauseTime, $stopSig, $obj->loop);
    }
    /**
     * Test generator loop.
     *
     * @param bool $stopSig Whether to stop with signal
     *
     *
     *
     * @dataProvider provideTrueFalse
     */
    public function testGenerator(bool $stopSig): void
    {
        $runCount = 0;
        $pauseTime = GenericLoop::PAUSE;
        $callable = function (GenericLoop $loop) use (&$runCount, &$pauseTime, &$l) {
            $l = $loop;
            $runCount++;
            return $pauseTime;
        };
        $this->fixtureAssertions($callable, $runCount, $pauseTime, $stopSig, $l);
        $obj = new class() {
            public $pauseTime = GenericLoop::PAUSE;
            public $runCount = 0;
            public ?GenericLoop $loop;
            public function run(GenericLoop $loop)
            {
                $this->loop = $loop;
                $this->runCount++;
                return $this->pauseTime;
            }
        };
        $this->fixtureAssertions([$obj, 'run'], $obj->runCount, $obj->pauseTime, $stopSig, $obj->loop);
        $obj = new class() {
            public $pauseTime = GenericLoop::PAUSE;
            public $runCount = 0;
            public ?GenericLoop $loop;
            public function run(GenericLoop $loop)
            {
                $this->loop = $loop;
                $this->runCount++;
                return $this->pauseTime;
            }
        };
        $this->fixtureAssertions(\Closure::fromCallable([$obj, 'run']), $obj->runCount, $obj->pauseTime, $stopSig, $obj->loop);
    }
    /**
     * Fixture assertions for started loop.
     */
    private function fixtureStarted(Loop&LoggingPauseInterface $loop, int $offset = 1): void
    {
        $this->assertTrue($loop->isRunning());
        $this->assertEquals($offset, $loop->startCounter());
        $this->assertEquals($offset-1, $loop->endCounter());
    }
    /**
     * Run fixture assertions.
     *
     * @param callable $closure   Closure
     * @param integer  $runCount  Run count
     * @param ?float   $pauseTime Pause time
     * @param bool     $stopSig   Whether to stop with signal
     */
    private function fixtureAssertions(callable $closure, int &$runCount, ?float &$pauseTime, bool $stopSig, ?GenericLoop &$l): void
    {
        $loop = new class($closure, Fixtures::LOOP_NAME) extends GenericLoop implements LoggingPauseInterface {
            use LoggingPause;
        };
        $expectedRunCount = 0;

        $this->assertEquals(Fixtures::LOOP_NAME, "$loop");

        $this->assertFalse($loop->isRunning());
        $this->assertEquals(0, $loop->startCounter());
        $this->assertEquals(0, $loop->endCounter());

        $this->assertEquals($expectedRunCount, $runCount);
        $this->assertEquals(0, $loop->getPauseCount());

        $this->assertTrue($loop->start());
        self::waitTick();
        $this->fixtureStarted($loop);
        $expectedRunCount++;
        $this->assertEquals($loop, $l);

        $this->assertEquals($expectedRunCount, $runCount);
        $this->assertEquals(1, $loop->getPauseCount());
        $this->assertEquals(0, $loop->getLastPause());
        $this->assertTrue($loop->isPaused());

        $pauseTime = 0.1;
        $this->assertTrue($loop->resume());
        self::waitTick();
        $this->fixtureStarted($loop);
        $expectedRunCount++;

        $this->assertEquals($expectedRunCount, $runCount);
        $this->assertEquals(2, $loop->getPauseCount());
        $this->assertEquals(0.1, $loop->getLastPause());
        $this->assertTrue($loop->isPaused());

        delay(0.048);
        $this->fixtureStarted($loop);

        $this->assertEquals($expectedRunCount, $runCount);
        $this->assertEquals(2, $loop->getPauseCount());
        $this->assertEquals(0.1, $loop->getLastPause());
        $this->assertTrue($loop->isPaused());

        delay(0.060);
        $this->fixtureStarted($loop);
        $expectedRunCount++;

        $this->assertEquals($expectedRunCount, $runCount);
        $this->assertEquals(3, $loop->getPauseCount());
        $this->assertEquals(0.1, $loop->getLastPause());
        $this->assertTrue($loop->isPaused());

        $this->assertTrue($loop->resume());
        self::waitTick();
        $expectedRunCount++;

        $this->assertEquals($expectedRunCount, $runCount);
        $this->assertEquals(4, $loop->getPauseCount());
        $this->assertEquals(0.1, $loop->getLastPause());
        $this->assertTrue($loop->isPaused());

        if ($stopSig) {
            $this->assertTrue($loop->stop());
        } else {
            $pauseTime = GenericLoop::STOP;
            $this->assertTrue($loop->resume());
            $expectedRunCount++;
        }
        self::waitTick();
        $this->assertEquals($expectedRunCount, $runCount);
        $this->assertEquals(4, $loop->getPauseCount());
        $this->assertEquals(0.1, $loop->getLastPause());
        $this->assertTrue($loop->isPaused());

        $this->assertEquals(1, $loop->startCounter());
        $this->assertEquals(1, $loop->endCounter());

        $this->assertFalse($loop->isRunning());
        $this->assertFalse($loop->stop());
        $this->assertFalse($loop->resume());

        // Restart loop
        $pauseTime = GenericLoop::PAUSE;
        $this->assertTrue($loop->start());
        self::waitTick();
        $this->fixtureStarted($loop, 2);
        $expectedRunCount++;

        $this->assertEquals($expectedRunCount, $runCount);
        $this->assertEquals(5, $loop->getPauseCount());
        $this->assertEquals(0.0, $loop->getLastPause());
        $this->assertTrue($loop->isPaused());

        if ($stopSig) {
            $this->assertTrue($loop->stop());
        } else {
            $pauseTime = GenericLoop::STOP;
            $this->assertTrue($loop->resume());
            $expectedRunCount++;
        }
        self::waitTick();
        $this->assertEquals($expectedRunCount, $runCount);
        $this->assertEquals(5, $loop->getPauseCount());
        $this->assertEquals(0.0, $loop->getLastPause());
        $this->assertTrue($loop->isPaused());

        $this->assertEquals(2, $loop->startCounter());
        $this->assertEquals(2, $loop->endCounter());

        $this->assertFalse($loop->isRunning());
        $this->assertFalse($loop->stop());
        $this->assertFalse($loop->resume());

        // Restart loop and stop it immediately
        $pauseTime = GenericLoop::PAUSE;
        $this->assertTrue($loop->start());
        $this->assertTrue($loop->stop());
        self::waitTick();

        $this->assertEquals($expectedRunCount, $runCount);
        $this->assertEquals(5, $loop->getPauseCount());
        $this->assertEquals(0.0, $loop->getLastPause());
        $this->assertTrue($loop->isPaused());

        $this->assertEquals(3, $loop->startCounter());
        $this->assertEquals(3, $loop->endCounter());

        $this->assertFalse($loop->isRunning());
        $this->assertFalse($loop->stop());
        $this->assertFalse($loop->resume());

        // Restart loop with delay and stop it immediately
        $pauseTime = 1.0;
        $this->assertTrue($loop->start());
        $this->assertTrue($loop->stop());
        self::waitTick();

        $this->assertEquals($expectedRunCount, $runCount);
        $this->assertEquals(5, $loop->getPauseCount());
        $this->assertEquals(0.0, $loop->getLastPause());
        $this->assertTrue($loop->isPaused());

        $this->assertEquals(4, $loop->startCounter());
        $this->assertEquals(4, $loop->endCounter());

        $this->assertFalse($loop->isRunning());
        $this->assertFalse($loop->stop());
        $this->assertFalse($loop->resume());

        // Restart loop, without postponing resuming
        $pauseTime = GenericLoop::PAUSE;
        $this->assertTrue($loop->start());
        self::waitTick();
        $this->fixtureStarted($loop, 5);
        $expectedRunCount++;

        $this->assertEquals($expectedRunCount, $runCount);
        $this->assertEquals(6, $loop->getPauseCount());
        $this->assertEquals(0.0, $loop->getLastPause());
        $this->assertTrue($loop->isPaused());

        $pauseTime = GenericLoop::STOP;
        $this->assertTrue($loop->resume(false));
        $this->assertTrue($loop->resume(false));
        $expectedRunCount++;
        self::waitTick();
        $this->assertEquals($expectedRunCount, $runCount);
        $this->assertEquals(6, $loop->getPauseCount());
        $this->assertEquals(0.0, $loop->getLastPause());
        $this->assertTrue($loop->isPaused());

        $this->assertEquals(5, $loop->startCounter());
        $this->assertEquals(5, $loop->endCounter());

        $this->assertFalse($loop->isRunning());
        $this->assertFalse($loop->stop());
        $this->assertFalse($loop->resume());

        // Restart loop, postponing resuming
        $pauseTime = GenericLoop::PAUSE;
        $this->assertTrue($loop->start());
        self::waitTick();
        $this->fixtureStarted($loop, 6);
        $expectedRunCount++;

        $this->assertEquals($expectedRunCount, $runCount);
        $this->assertEquals(7, $loop->getPauseCount());
        $this->assertEquals(0.0, $loop->getLastPause());
        $this->assertTrue($loop->isPaused());

        $pauseTime = GenericLoop::STOP;
        $this->assertTrue($loop->resume(true));
        EventLoop::queue(fn () => $this->assertTrue($loop->resume(true)));
        self::waitTick();
        $this->assertEquals($expectedRunCount, $runCount);
        $this->assertEquals(7, $loop->getPauseCount());
        $this->assertEquals(0.0, $loop->getLastPause());
        $this->assertTrue($loop->isPaused());

        $this->assertEquals(6, $loop->startCounter());
        $this->assertEquals(5, $loop->endCounter());

        self::waitTick();
        $expectedRunCount++;
        $this->assertEquals($expectedRunCount, $runCount);
        $this->assertEquals(7, $loop->getPauseCount());
        $this->assertEquals(0.0, $loop->getLastPause());
        $this->assertTrue($loop->isPaused());

        $this->assertEquals(6, $loop->startCounter());
        $this->assertEquals(6, $loop->endCounter());
    }

    /**
     * Provide true false.
     *
     */
    public function provideTrueFalse(): array
    {
        return [
            [true],
            [false]
        ];
    }
}
