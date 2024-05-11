<?php declare(strict_types=1);
/**
 * Loop test.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2020 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/MIT MIT
 */

namespace danog\Loop\Test;

use danog\Loop\Loop;
use danog\Loop\PeriodicLoop;
use danog\Loop\Test\Interfaces\LoggingInterface;
use danog\Loop\Test\Traits\Basic;
use danog\Loop\Test\Traits\Logging;

use function Amp\delay;

class PeriodicTest extends Fixtures
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
        $retValue = false;
        $callable = function (?PeriodicLoop $loop) use (&$runCount, &$retValue, &$l) {
            $l = $loop;
            $runCount++;
            return $retValue;
        };
        $this->fixtureAssertions($callable, $runCount, $retValue, $stopSig, $l);
        $obj = new class() {
            public $retValue = false;
            public $runCount = 0;
            public ?PeriodicLoop $loop = null;
            public function run(PeriodicLoop $periodicLoop)
            {
                $this->loop = $periodicLoop;
                $this->runCount++;
                return $this->retValue;
            }
        };
        $this->fixtureAssertions([$obj, 'run'], $obj->runCount, $obj->retValue, $stopSig, $obj->loop);
        $obj = new class() {
            public $retValue = false;
            public $runCount = 0;
            public ?PeriodicLoop $loop = null;
            public function run(PeriodicLoop $periodicLoop)
            {
                $this->loop = $periodicLoop;
                $this->runCount++;
                return $this->retValue;
            }
        };
        $this->fixtureAssertions(\Closure::fromCallable([$obj, 'run']), $obj->runCount, $obj->retValue, $stopSig, $obj->loop);
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
        $retValue = false;
        $callable = function (?PeriodicLoop $loop) use (&$runCount, &$retValue, &$l) {
            $l = $loop;
            $runCount++;
            return $retValue;
        };
        $this->fixtureAssertions($callable, $runCount, $retValue, $stopSig, $l);
        $obj = new class() {
            public $retValue = false;
            public $runCount = 0;
            public ?PeriodicLoop $loop = null;
            public function run(?PeriodicLoop $loop)
            {
                $this->loop = $loop;
                $this->runCount++;
                return $this->retValue;
            }
        };
        $this->fixtureAssertions([$obj, 'run'], $obj->runCount, $obj->retValue, $stopSig, $obj->loop);
        $obj = new class() {
            public $retValue = false;
            public $runCount = 0;
            public ?PeriodicLoop $loop = null;
            public function run(?PeriodicLoop $loop)
            {
                $this->loop = $loop;
                $this->runCount++;
                return $this->retValue;
            }
        };
        $this->fixtureAssertions(\Closure::fromCallable([$obj, 'run']), $obj->runCount, $obj->retValue, $stopSig, $obj->loop);
    }
    /**
     * Fixture assertions for started loop.
     */
    private function fixtureStarted(PeriodicLoop&LoggingInterface $loop): void
    {
        $this->assertTrue($loop->isRunning());
        $this->assertEquals(1, $loop->startCounter());
        $this->assertEquals(0, $loop->endCounter());
    }
    /**
     * Run fixture assertions.
     *
     * @param callable $closure  Closure
     * @param integer  $runCount Run count
     * @param bool     $retValue Pause time
     * @param bool     $stopSig  Whether to stop with signal
     */
    private function fixtureAssertions(callable $closure, int &$runCount, bool &$retValue, bool $stopSig, ?PeriodicLoop &$l): void
    {
        $loop = new class($closure, Fixtures::LOOP_NAME, 0.1) extends PeriodicLoop implements LoggingInterface {
            use Logging;
        };
        $this->assertEquals(Fixtures::LOOP_NAME, "$loop");

        $this->assertFalse($loop->isRunning());
        $this->assertEquals(0, $loop->startCounter());
        $this->assertEquals(0, $loop->endCounter());

        $this->assertEquals(0, $runCount);

        $this->assertTrue($loop->start());
        $this->fixtureStarted($loop);
        self::waitTick();

        $this->assertTrue($loop->isPaused());
        $this->assertEquals(1, $runCount);

        $this->assertEquals($loop, $l);

        delay(0.048);
        $this->fixtureStarted($loop);

        $this->assertTrue($loop->isPaused());
        $this->assertEquals(1, $runCount);

        delay(0.060);
        $this->fixtureStarted($loop);

        $this->assertTrue($loop->isPaused());
        $this->assertEquals(2, $runCount);

        $this->assertTrue($loop->resume());
        self::waitTick();

        $this->assertTrue($loop->isPaused());
        $this->assertEquals(3, $runCount);

        if ($stopSig) {
            $this->assertTrue($loop->stop());
        } else {
            $retValue = true;
            $this->assertTrue($loop->resume());
        }
        self::waitTick();
        $this->assertEquals($stopSig ? 3 : 4, $runCount);

        $this->assertTrue($loop->isPaused());
        $this->assertFalse($loop->isRunning());

        $this->assertEquals(1, $loop->startCounter());
        $this->assertEquals(1, $loop->endCounter());
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
