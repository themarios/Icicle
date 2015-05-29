<?php
namespace Icicle\Tests\Loop;

use Icicle\Loop;
use Icicle\Loop\SelectLoop;
use Icicle\Tests\TestCase;

class LoopTest extends TestCase
{
    const TIMEOUT = 0.1;
    const WRITE_STRING = 'abcdefghijklmnopqrstuvwxyz';
    
    public function createSockets()
    {
        return stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    }
    
    public function testLoop()
    {
        $loop = new SelectLoop();
        
        Loop\loop($loop);
        
        $this->assertSame($loop, Loop\loop());
    }
    
    /**
     * @depends testLoop
     * @expectedException \Icicle\Loop\Exception\InitializedException
     */
    public function testLoopAfterInitialized()
    {
        $loop = Loop\loop();
        
        Loop\loop($loop);
    }

    /**
     * @depends testLoop
     */
    public function testSchedule()
    {
        Loop\schedule($this->createCallback(1));
        
        Loop\tick(true);
    }
    
    /**
     * @depends testSchedule
     */
    public function testScheduleWithArguments()
    {
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with(1, 2, 3.14, 'test');
        
        Loop\schedule($callback, 1, 2, 3.14, 'test');
        
        Loop\tick(true);
    }

    /**
     * @depends testSchedule
     */
    public function testIsEmpty()
    {
        $this->assertTrue(Loop\isEmpty());

        Loop\schedule(function () {});

        $this->assertFalse(Loop\isEmpty());

        Loop\tick(true);
    }

    public function testPoll()
    {
        list($readable, $writable) = $this->createSockets();
        
        fwrite($writable, self::WRITE_STRING);
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($readable, false);
        
        $poll = Loop\poll($readable, $callback);
        
        $this->assertInstanceOf('Icicle\Loop\Events\SocketEventInterface', $poll);
        
        $poll->listen();
        
        Loop\run();
    }
    
    public function testAwait()
    {
        list($readable, $writable) = $this->createSockets();
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($writable, false);
        
        $await = Loop\await($writable, $callback);
        
        $this->assertInstanceOf('Icicle\Loop\Events\SocketEventInterface', $await);
        
        $await->listen();
        
        Loop\run();
    }
    
    public function testTimer()
    {
        $timer = Loop\timer(self::TIMEOUT, $this->createCallback(1));
        
        $this->assertInstanceOf('Icicle\Loop\Events\TimerInterface', $timer);
        
        Loop\run();
    }
    
    /**
     * @depends testTimer
     */
    public function testTimerWithArguments()
    {
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with(1, 2, 3.14, 'test');
        
        $timer = Loop\timer(self::TIMEOUT, $callback, 1, 2, 3.14, 'test');
        
        $this->assertInstanceOf('Icicle\Loop\Events\TimerInterface', $timer);
        
        Loop\tick(true);
    }
    
    public function testPeriodic()
    {
        $callback = $this->createCallback(1);
        
        $callback = function () use (&$timer, $callback) {
            $callback();
            $timer->stop();
        };
        
        $timer = Loop\periodic(self::TIMEOUT, $callback);
        
        $this->assertInstanceOf('Icicle\Loop\Events\TimerInterface', $timer);
        
        Loop\run();
    }
    
    /**
     * @depends testPeriodic
     */
    public function testPeriodicWithArguments()
    {
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with(1, 2, 3.14, 'test');
        
        $callback = function (/* ...$args */) use (&$timer, $callback) {
            $timer->stop();
            call_user_func_array($callback, func_get_args());
        };
        
        $timer = Loop\periodic(self::TIMEOUT, $callback, 1, 2, 3.14, 'test');
        
        $this->assertInstanceOf('Icicle\Loop\Events\TimerInterface', $timer);
        
        Loop\run();
    }
    
    public function testImmediate()
    {
        $immediate = Loop\immediate($this->createCallback(1));
        
        $this->assertInstanceOf('Icicle\Loop\Events\ImmediateInterface', $immediate);
        
        Loop\run();
    }
    
    /**
     * @depends testImmediate
     */
    public function testImmediateWithArguments()
    {
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with(1, 2, 3.14, 'test');
        
        $immediate = Loop\immediate($callback, 1, 2, 3.14, 'test');
        
        $this->assertInstanceOf('Icicle\Loop\Events\ImmediateInterface', $immediate);
        
        Loop\run();
    }
    
    /**
     * @depends testSchedule
     */
    public function testScheduleWithinScheduledCallback()
    {
        $callback = function () {
            Loop\schedule($this->createCallback(1));
        };
        
        Loop\schedule($callback);
        
        Loop\tick(true);
    }
    
    /**
     * @depends testSchedule
     */
    public function testMaxScheduleDepth()
    {
        $previous = Loop\maxScheduleDepth(1);
        
        $this->assertSame(1, Loop\maxScheduleDepth());
        
        Loop\schedule($this->createCallback(1));
        Loop\schedule($this->createCallback(0));
        
        Loop\tick(true);
        
        Loop\maxScheduleDepth($previous);
        
        $this->assertSame($previous, Loop\maxScheduleDepth());
    }
    
    /**
     * @depends testLoop
     */
    public function testSignalHandlingEnabled()
    {
        $this->assertSame(extension_loaded('pcntl'), Loop\signalHandlingEnabled());
    }
    
    /**
     * @depends testSchedule
     */
    public function testIsRunning()
    {
        $callback = function () {
            $this->assertTrue(Loop\isRunning());
        };
        
        Loop\schedule($callback);
        
        Loop\run();
        
        $callback = function () {
            $this->assertFalse(Loop\isRunning());
        };
        
        Loop\schedule($callback);
        
        Loop\tick(true);
    }
    
    /**
     * @depends testIsRunning
     */
    public function testStop()
    {
        $callback = function () {
            Loop\stop();
            $this->assertFalse(Loop\isRunning());
        };
        
        Loop\schedule($callback);
        
        $this->assertTrue(Loop\run());
    }
    
    /**
     * @requires extension pcntl
     * @depends testLoop
     */
    public function testSignal()
    {
        $pid = posix_getpid();
        
        $callback1 = $this->createCallback(1);
        $callback1->method('__invoke')
                  ->with($this->identicalTo(SIGUSR1));
        
        $callback2 = $this->createCallback(1);
        $callback2->method('__invoke')
                  ->with($this->identicalTo(SIGUSR2));
        
        $callback3 = $this->createCallback(1);
        
        $signal = Loop\signal(SIGUSR1, $callback1);
        $this->assertInstanceOf('Icicle\Loop\Events\SignalInterface', $signal);

        $signal = Loop\signal(SIGUSR2, $callback2);
        $this->assertInstanceOf('Icicle\Loop\Events\SignalInterface', $signal);

        $signal = Loop\signal(SIGUSR1, $callback3);
        $this->assertInstanceOf('Icicle\Loop\Events\SignalInterface', $signal);
        
        posix_kill($pid, SIGUSR1);
        posix_kill($pid, SIGUSR2);
        
        Loop\tick(false);
    }
    
    /**
     * @depends testLoop
     */
    public function testClear()
    {
        Loop\schedule($this->createCallback(0));
        
        Loop\clear();
        
        Loop\tick(false);
    }
    
    /**
     * @depends testLoop
     */
    public function testReInit()
    {
        Loop\schedule($this->createCallback(1));
        
        Loop\reInit();
        
        Loop\tick(false);
    }
}
