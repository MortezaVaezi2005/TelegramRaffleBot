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
use danog\Loop\Test\Interfaces\BasicInterface;
use danog\Loop\Test\Traits\Basic;
use danog\Loop\Test\Traits\BasicException;
use Revolt\EventLoop;
use RuntimeException;

use function Amp\delay;

class LoopTest extends Fixtures
{
    /**
     * Execute pre-start assertions.
     */
    private function assertPreStart(BasicInterface&Loop $loop): void
    {
        $this->assertEquals(self::LOOP_NAME, "$loop");

        $this->assertFalse($loop->isRunning());
        $this->assertFalse($loop->ran());

        $this->assertFalse($loop->inited());

        $this->assertEquals(0, $loop->startCounter());
        $this->assertEquals(0, $loop->endCounter());
    }
    /**
     * Execute after-start assertions.
     */
    private function assertAfterStart(BasicInterface&Loop $loop, int $prevRun = 0): void
    {
        self::waitTick();
        $this->assertTrue($loop->inited());

        if ($prevRun === 0) {
            $this->assertFalse($loop->ran());
        } else {
            $this->assertTrue($loop->ran());
        }

        $this->assertTrue($loop->isRunning());

        $this->assertEquals($prevRun+1, $loop->startCounter());
        $this->assertEquals($prevRun, $loop->endCounter());

        $this->assertFalse($loop->start());
        $this->assertFalse($loop->isPaused());
    }
    /**
     * Execute final assertions.
     */
    private function assertFinal(BasicInterface&Loop $loop, int $count = 1): void
    {
        $this->assertTrue($loop->ran());
        $this->assertFalse($loop->isRunning());

        $this->assertTrue($loop->inited());

        $this->assertEquals($count, $loop->startCounter());
        $this->assertEquals($count, $loop->endCounter());
    }
    /**
     * Test basic loop.
     */
    public function testLoop(): void
    {
        $loop = new class() extends Loop implements BasicInterface {
            use Basic;
        };
        $this->assertPreStart($loop);
        $this->assertTrue($loop->start());
        $this->assertAfterStart($loop);

        delay(0.110);

        $this->assertFinal($loop);
    }
    /**
     * Test basic loop.
     */
    public function testLoopStopFromOutside(): void
    {
        $loop = new class() extends Loop implements BasicInterface {
            use Basic;
            /**
             * Loop implementation.
             */
            public function loop(): ?float
            {
                $this->inited = true;
                delay(0.1);
                $this->ran = true;
                return 1000.0;
            }
        };
        $this->assertPreStart($loop);
        $this->assertTrue($loop->start());
        $this->assertAfterStart($loop);

        $this->assertTrue($loop->stop());
        delay(0.110);

        $this->assertFinal($loop);
    }
    /**
     * Test basic loop.
     */
    public function testLoopStopFromOutsideRestart(): void
    {
        $loop = new class() extends Loop implements BasicInterface {
            use Basic;
            /**
             * Loop implementation.
             */
            public function loop(): ?float
            {
                $this->inited = true;
                delay(0.1);
                $this->ran = true;
                return 1000.0;
            }
        };
        $this->assertPreStart($loop);
        $this->assertTrue($loop->start());
        $this->assertAfterStart($loop);

        EventLoop::queue(function () use ($loop): void {
            $this->assertTrue($loop->stop());
        });
        self::waitTick();

        $this->assertTrue($loop->start());
        $this->assertAfterStart($loop, 1);

        $this->assertTrue($loop->stop());
        delay(0.110);

        $this->assertFinal($loop, 2);
    }
    /**
     * Test basic exception in loop.
     */
    public function testException(): void
    {
        $loop = new class() extends Loop implements BasicInterface {
            use BasicException;
        };

        $e_thrown = null;
        EventLoop::setErrorHandler(function (\RuntimeException $e) use (&$e_thrown): void {
            $e_thrown = $e;
        });

        $this->assertPreStart($loop);
        $this->assertTrue($loop->start());
        self::waitTick();
        $this->assertFalse($loop->isRunning());

        $this->assertTrue($loop->inited());

        $this->assertEquals(1, $loop->startCounter());
        $this->assertEquals(1, $loop->endCounter());

        $this->assertInstanceOf(RuntimeException::class, $e_thrown);

        EventLoop::setErrorHandler(null);
    }
}
