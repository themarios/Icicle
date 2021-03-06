<?php
namespace Icicle\Tests\Loop;

use Exception;
use Icicle\Loop\Events\EventFactoryInterface;
use Icicle\Loop\Events\Manager\SignalManagerInterface;
use Icicle\Loop\LoopInterface;
use Icicle\Loop\Exception\LogicException;
use Icicle\Loop\Events\Manager\ImmediateManagerInterface;
use Icicle\Loop\Events\Manager\SocketManagerInterface;
use Icicle\Loop\Events\Manager\TimerManagerInterface;
use Icicle\Tests\TestCase;

/**
 * Abstract class to be used as a base to test loop implementations.
 */
abstract class AbstractLoopTest extends TestCase
{
    const TIMEOUT = 0.1;
    const RUNTIME = 0.05; // Allowed deviation from projected run times.
    const MICROSEC_PER_SEC = 1e6;
    const WRITE_STRING = '1234567890';
    const RESOURCE = 1;
    const CHUNK_SIZE = 8192;

    /**
     * @var \Icicle\Loop\LoopInterface
     */
    protected $loop;
    
    public function setUp()
    {
        $this->loop = $this->createLoop($this->createEventFactory());
    }
    
    /**
     * Creates the loop implementation to test.
     *
     * @param \Icicle\Loop\Events\EventFactoryInterface $eventFactory
     *
     * @return LoopInterface
     */
    abstract public function createLoop(EventFactoryInterface $eventFactory);

    /**
     * @return EventFactoryInterface
     */
    public function createEventFactory()
    {
        $factory = $this->getMockBuilder('Icicle\Loop\Events\EventFactoryInterface')
                        ->getMock();
        
        $factory->method('socket')
            ->will($this->returnCallback(function (SocketManagerInterface $manager, $resource, callable $callback) {
                return $this->createSocketEvent($manager, $resource, $callback);
            }));
        
        $factory->method('timer')
            ->will($this->returnCallback(
                function (TimerManagerInterface $manager,  $interval, $periodic, callable $callback, array $args = null) {
                    return $this->createTimer($manager, $interval, $periodic, $callback, $args);
                }
            ));
        
        $factory->method('immediate')
            ->will($this->returnCallback(function (ImmediateManagerInterface $manager, callable $callback, array $args = null) {
                return $this->createImmediate($manager, $callback, $args);
            }));

        $factory->method('signal')
            ->will($this->returnCallback(function (SignalManagerInterface $manager, $signo, callable $callback) {
                return $this->createSignal($manager, $signo, $callback);
            }));
        
        return $factory;
    }
    
    public function createSockets()
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($sockets[1], self::WRITE_STRING); // Make $sockets[0] readable.
        
        return $sockets;
    }
    
    public function createSocketEvent(SocketManagerInterface $manager, $resource, callable $callback)
    {
        $socket = $this->getMockBuilder('Icicle\Loop\Events\SocketEventInterface')
                     ->getMock();
        
        $socket->method('getResource')
            ->will($this->returnValue($resource));
        
        $socket->method('call')
            ->will($this->returnCallback($callback));
        
        $socket->method('listen')
            ->will($this->returnCallback(function ($timeout) use ($socket, $manager) {
                $manager->listen($socket, $timeout);
            }));
        
        $socket->method('isPending')
            ->will($this->returnCallback(function () use ($socket, $manager) {
                return $manager->isPending($socket);
            }));
        
        $socket->method('cancel')
            ->will($this->returnCallback(function () use ($socket, $manager) {
                $manager->cancel($socket);
            }));
    
        $socket->method('free')
            ->will($this->returnCallback(function () use ($socket, $manager) {
                $manager->free($socket);
            }));
    
        $socket->method('isFreed')
            ->will($this->returnCallback(function () use ($socket, $manager) {
                return $manager->isFreed($socket);
            }));
        
        return $socket;
    }
    
    public function createImmediate(ImmediateManagerInterface $manager, callable $callback, array $args = null)
    {
        $immediate = $this->getMockBuilder('Icicle\Loop\Events\ImmediateInterface')
                          ->getMock();
        
        if (!empty($args)) {
            $callback = function () use ($callback, $args) {
                call_user_func_array($callback, $args);
            };
        }
        
        $immediate->method('call')
            ->will($this->returnCallback($callback));
        
        $immediate->method('execute')
            ->will($this->returnCallback(function () use ($immediate, $manager) {
                $manager->execute($immediate);
            }));

        $immediate->method('cancel')
            ->will($this->returnCallback(function () use ($immediate, $manager) {
                $manager->cancel($immediate);
            }));
    
        $immediate->method('isPending')
            ->will($this->returnCallback(function () use ($immediate, $manager) {
                return $manager->isPending($immediate);
            }));
        
        return $immediate;
    }
    
    public function createTimer(
        TimerManagerInterface $manager,
        $interval = self::TIMEOUT,
        $periodic = false,
        callable $callback,
        array $args = null
    ) {
        $timer = $this->getMockBuilder('Icicle\Loop\Events\TimerInterface')
                      ->getMock();
        
        if (!empty($args)) {
            $callback = function () use ($callback, $args) {
                call_user_func_array($callback, $args);
            };
        }
        
        $timer->method('call')
            ->will($this->returnCallback($callback));
        
        $timer->method('getInterval')
            ->will($this->returnValue((float) $interval));
        
        $timer->method('isPeriodic')
            ->will($this->returnValue((bool) $periodic));

        $timer->method('start')
            ->will($this->returnCallback(function () use ($timer, $manager) {
                $manager->start($timer);
            }));

        $timer->method('stop')
            ->will($this->returnCallback(function () use ($timer, $manager) {
                $manager->stop($timer);
            }));
    
        $timer->method('isPending')
            ->will($this->returnCallback(function () use ($timer, $manager) {
                return $manager->isPending($timer);
            }));

        $timer->method('unreference')
            ->will($this->returnCallback(function () use ($timer, $manager) {
                $manager->unreference($timer);
            }));

        $timer->method('reference')
            ->will($this->returnCallback(function () use ($timer, $manager) {
                $manager->reference($timer);
            }));

        return $timer;
    }

    public function createSignal(SignalManagerInterface $manager, $signo, callable $callback)
    {
        $signal = $this->getMockBuilder('Icicle\Loop\Events\SignalInterface')
                       ->getMock();

        $signal->method('getSignal')
            ->will($this->returnValue($signo));

        $signal->method('call')
            ->will($this->returnCallback(function () use ($callback, $signo) {
                $callback($signo);
            }));

        $signal->method('enable')
            ->will($this->returnCallback(function () use ($signal, $manager) {
                $manager->enable($signal);
            }));

        $signal->method('disable')
            ->will($this->returnCallback(function () use ($signal, $manager) {
                $manager->disable($signal);
            }));

        $signal->method('isEnabled')
            ->will($this->returnCallback(function () use ($signal, $manager) {
                return $manager->isEnabled($signal);
            }));

        return $signal;
    }
    
    public function testNoBlockingOnEmptyLoop()
    {
        $this->assertTrue($this->loop->isEmpty()); // Loop should be empty upon creation.
        
        $this->assertRunTimeLessThan([$this->loop, 'run'], self::RUNTIME); // An empty loop should not block.
    }
    
    public function testCreatePoll()
    {
        list($socket) = $this->createSockets();
        
        $poll = $this->loop->poll($socket, $this->createCallback(0));
        
        $this->assertInstanceOf('Icicle\Loop\Events\SocketEventInterface', $poll);
    }
    
    /**
     * @depends testCreatePoll
     * @expectedException \Icicle\Loop\Exception\ResourceBusyException
     */
    public function testDoublePoll()
    {
        list($socket) = $this->createSockets();
        
        $poll = $this->loop->poll($socket, $this->createCallback(0));
        
        $poll = $this->loop->poll($socket, $this->createCallback(0));
    }
    
    /**
     * @depends testCreatePoll
     */
    public function testListenPoll()
    {
        list($socket) = $this->createSockets();
        
        $callback = $this->createCallback(1);
        
        $callback->method('__invoke')
                 ->with($this->identicalTo(false));
        
        $poll = $this->loop->poll($socket, $callback);
        
        $poll->listen();
        
        $this->assertTrue($poll->isPending());
        
        $this->loop->tick(false); // Should invoke callback.
    }
    
    /**
     * @depends testListenPoll
     */
    public function testCanelPoll()
    {
        list($socket) = $this->createSockets();
        
        $poll = $this->loop->poll($socket, $this->createCallback(0));
        
        $poll->listen();
        
        $poll->cancel();
        
        $this->assertFalse($poll->isPending());
        
        $this->loop->tick(false); // Should not invoke callback.
        
        $this->assertFalse($poll->isPending());
    }
    
    /**
     * @depends testListenPoll
     */
    public function testRelistenPoll()
    {
        list($socket) = $this->createSockets();
        
        $callback = $this->createCallback(2);
        
        $callback->method('__invoke')
                 ->with($this->identicalTo(false));
        
        $poll = $this->loop->poll($socket, $callback);
        
        $poll->listen();
        
        $this->assertTrue($poll->isPending());
        
        $this->loop->tick(false); // Should invoke callback.
        
        $poll->listen();
        
        $this->assertTrue($poll->isPending());
        
        $this->loop->tick(false); // Should invoke callback.
    }
    
    /**
     * @depends testListenPoll
     */
    public function testListenPollWithTimeout()
    {
        list($readable, $writable) = $this->createSockets();
        
        $callback = $this->createCallback(1);
        
        $callback->method('__invoke')
                 ->with($this->identicalTo(false));
        
        $poll = $this->loop->poll($readable, $callback);
        
        $poll->listen(self::TIMEOUT);
        
        $this->loop->tick(false);
    }
    
    /**
     * @depends testListenPollWithTimeout
     */
    public function testListenPollWithExpiredTimeout()
    {
        list($readable, $writable) = $this->createSockets();
        
        $callback = $this->createCallback(1);
        
        $callback->method('__invoke')
                 ->with($this->identicalTo(true));
        
        $poll = $this->loop->poll($writable, $callback);
        
        $poll->listen(self::TIMEOUT);
        
        usleep(self::TIMEOUT * self::MICROSEC_PER_SEC);
        
        $this->loop->tick(false);
    }
    
    /**
     * @depends testListenPollWithTimeout
     */
    public function testListenPollWithInvalidTimeout()
    {
        list($readable, $writable) = $this->createSockets();
        
        $callback = $this->createCallback(1);
        
        $callback->method('__invoke')
                 ->with($this->identicalTo(true));
        
        $poll = $this->loop->poll($writable, $callback);
        
        $poll->listen(-1);
        
        usleep(self::TIMEOUT * self::MICROSEC_PER_SEC);
        
        $this->loop->tick(false);
    }
    
    /**
     * @depends testListenPollWithTimeout
     */
    public function testCancelPollWithTimeout()
    {
        list($socket) = $this->createSockets();
        
        $poll = $this->loop->poll($socket, $this->createCallback(0));
        
        $poll->listen(self::TIMEOUT);
        
        $poll->cancel($poll);
        
        $this->assertFalse($poll->isPending());
        
        $this->loop->tick(false); // Should not invoke callback.
        
        $this->assertFalse($poll->isPending());
    }
    
    /**
     * @depends testListenPoll
     * @expectedException \Icicle\Loop\Exception\FreedException
     */
    public function testFreePoll()
    {
        list($socket) = $this->createSockets();
        
        $poll = $this->loop->poll($socket, $this->createCallback(0));
        
        $poll->listen();
        
        $this->assertFalse($poll->isFreed());
        
        $poll->free();
        
        $this->assertTrue($poll->isFreed());
        $this->assertFalse($poll->isPending());
        
        $poll->listen();
    }
    
    /**
     * @depends testFreePoll
     * @expectedException \Icicle\Loop\Exception\FreedException
     */
    public function testFreePollWithTimeout()
    {
        list($socket) = $this->createSockets();
        
        $poll = $this->loop->poll($socket, $this->createCallback(0));
        
        $poll->listen(self::TIMEOUT);
        
        $this->assertFalse($poll->isFreed());
        
        $poll->free();
        
        $this->assertTrue($poll->isFreed());
        $this->assertFalse($poll->isPending());
        
        $poll->listen(self::TIMEOUT);
    }
    
    public function testCreateAwait()
    {
        list( , $socket) = $this->createSockets();
        
        $await = $this->loop->await($socket, $this->createCallback(0));
        
        $this->assertInstanceOf('Icicle\Loop\Events\SocketEventInterface', $await);
    }
    
    /**
     * @depends testCreateAwait
     * @expectedException \Icicle\Loop\Exception\ResourceBusyException
     */
    public function testDoubleAwait()
    {
        list( , $socket) = $this->createSockets();
        
        $await = $this->loop->await($socket, $this->createCallback(0));
        
        $await = $this->loop->await($socket, $this->createCallback(0));
    }
    
    /**
     * @depends testCreateAwait
     */
    public function testListenAwait()
    {
        list( , $socket) = $this->createSockets();
        
        $callback = $this->createCallback(1);
        
        $callback->method('__invoke')
                 ->with($this->identicalTo(false));
        
        $await = $this->loop->await($socket, $callback);
        
        $await->listen();
        
        $this->assertTrue($await->isPending());
        
        $this->loop->tick(false); // Should invoke callback.
    }
    
    /**
     * @depends testListenAwait
     */
    public function testRelistenAwait()
    {
        list($socket) = $this->createSockets();
        
        $callback = $this->createCallback(2);
        
        $callback->method('__invoke')
                 ->with($this->identicalTo(false));
        
        $await = $this->loop->await($socket, $callback);
        
        $await->listen();
        
        $this->assertTrue($await->isPending());
        
        $this->loop->tick(false); // Should invoke callback.
        
        $await->listen();
        
        $this->assertTrue($await->isPending());
        
        $this->loop->tick(false); // Should invoke callback.
    }
    
    /**
     * @depends testListenAwait
     */
    public function testCancelAwait()
    {
        list($socket) = $this->createSockets();
        
        $await = $this->loop->await($socket, $this->createCallback(0));
        
        $await->listen();
        
        $await->cancel();
        
        $this->assertFalse($await->isPending());
        
        $this->loop->tick(false); // Should not invoke callback.
        
        $this->assertFalse($await->isFreed());
    }
    
    /**
     * @depends testListenPoll
     */
    public function testListenAwaitWithTimeout()
    {
        list($readable, $writable) = $this->createSockets();
        
        $callback = $this->createCallback(1);
        
        $callback->method('__invoke')
                 ->with($this->identicalTo(false));
        
        $await = $this->loop->await($writable, $callback);
        
        $await->listen(self::TIMEOUT);
        
        $this->loop->tick(false);
    }

    /**
     * @depends testListenPollWithTimeout
     */
    public function testListenAwaitWithInvalidTimeout()
    {
        list($readable, $writable) = $this->createSockets();
        
        $callback = $this->createCallback(1);
        
        $await = $this->loop->await($writable, $callback);
        
        $await->listen(-1);
        
        $this->loop->tick(false);
    }
    
    /**
     * @depends testCancelAwait
     */
    public function testCancelAwaitWithTimeout()
    {
        list($socket) = $this->createSockets();
        
        $await = $this->loop->await($socket, $this->createCallback(0));
        
        $await->listen(self::TIMEOUT);
        
        $await->cancel();
        
        $this->assertFalse($await->isPending());
        
        $this->loop->tick(false); // Should not invoke callback.
        
        $this->assertFalse($await->isFreed());
    }
    
    /**
     * @depends testListenAwait
     * @expectedException \Icicle\Loop\Exception\FreedException
     */
    public function testFreeAwait()
    {
        list($socket) = $this->createSockets();
        
        $await = $this->loop->await($socket, $this->createCallback(0));
        
        $await->listen();
        
        $this->assertFalse($await->isFreed());
        
        $await->free();
        
        $this->assertTrue($await->isFreed());
        $this->assertFalse($await->isPending());
        
        $await->listen();
    }
    
    /**
     * @depends testFreeAwait
     * @expectedException \Icicle\Loop\Exception\FreedException
     */
    public function testFreeAwaitWithTimeout()
    {
        list($socket) = $this->createSockets();
        
        $await = $this->loop->await($socket, $this->createCallback(0));
        
        $await->listen(self::TIMEOUT);
        
        $this->assertFalse($await->isFreed());
        
        $await->free();
        
        $this->assertTrue($await->isFreed());
        $this->assertFalse($await->isPending());
        
        $await->listen(self::TIMEOUT);
    }
    
    /**
     * @depends testListenPoll
     * @expectedException \Icicle\Loop\Exception\LogicException
     */
    public function testRunThrowsAfterThrownExceptionFromPollCallback()
    {
        list($socket) = $this->createSockets();
        
        $exception = new LogicException();
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->will($this->throwException($exception));
        
        $poll = $this->loop->poll($socket, $callback);
        
        $poll->listen();
        
        try {
            $this->loop->run(); // Exception should be thrown from loop.
        } catch (Exception $e) {
            $this->assertSame($exception, $e);
            $this->assertFalse($this->loop->isRunning()); // Loop should report that it has stopped.
            throw $e;
        }
        
        $this->fail('Loop should not catch exceptions thrown from poll callbacks.');
    }    
    
    /**
     * @depends testListenAwait
     * @expectedException \Icicle\Loop\Exception\LogicException
     */
    public function testRunThrowsAfterThrownExceptionFromAwaitCallback()
    {
        list( , $socket) = $this->createSockets();
        
        $exception = new LogicException();
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->will($this->throwException($exception));
        
        $await = $this->loop->await($socket, $callback);
        
        $await->listen();
        
        try {
            $this->loop->run(); // Exception should be thrown from loop.
        } catch (Exception $e) {
            $this->assertSame($exception, $e);
            $this->assertFalse($this->loop->isRunning()); // Loop should report that it has stopped.
            throw $e;
        }
        
        $this->fail('Loop should not catch exceptions thrown from await callbacks.');
    }
    
    public function testSchedule()
    {
        $callback = $this->createCallback(3);
        
        $this->loop->schedule($callback);
        $this->loop->schedule($callback);
        
        $this->loop->run();
        
        $this->loop->schedule($callback);
        
        $this->loop->run();
    }
    
    /**
     * @depends testSchedule
     */
    public function testScheduleWithArguments()
    {
        $args = ['test1', 'test2', 'test3'];
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($this->identicalTo($args[0]), $this->identicalTo($args[1]), $this->identicalTo($args[2]));
        
        $this->loop->schedule($callback, $args);
        
        $this->loop->run();
    }
    
    /**
     * @depends testSchedule
     */
    public function testScheduleWithinScheduledCallback()
    {
        $callback = function () {
            $this->loop->schedule($this->createCallback(1));
        };
        
        $this->loop->schedule($callback);
        
        $this->loop->run();
    }
    
    /**
     * @depends testSchedule
     */
    public function testMaxScheduleDepth()
    {
        $depth = 10;
        $ticks = 2;
        
        $previous = $this->loop->maxScheduleDepth($depth);
        
        $this->assertSame($depth, $this->loop->maxScheduleDepth());
        
        $callback = $this->createCallback($depth * $ticks);
        
        for ($i = 0; $depth * ($ticks + $ticks) > $i; ++$i) {
            $this->loop->schedule($callback);
        }
        
        for ($i = 0; $ticks > $i; ++$i) {
            $this->loop->tick(false);
        }
        
        $this->loop->maxScheduleDepth($previous);
        
        $this->assertSame($previous, $this->loop->maxScheduleDepth());
    }
    
    /**
     * @depends testSchedule
     * @expectedException \Icicle\Loop\Exception\LogicException
     */
    public function testRunThrowsAfterThrownExceptionFromScheduleCallback()
    {
        $exception = new LogicException();
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->will($this->throwException($exception));
        
        $this->loop->schedule($callback);
        
        try {
            $this->loop->run(); // Exception should be thrown from loop.
        } catch (Exception $e) {
            $this->assertSame($exception, $e);
            $this->assertFalse($this->loop->isRunning()); // Loop should report that it has stopped.
            throw $e;
        }
        
        $this->fail('Loop should not catch exceptions thrown from scheduled callbacks.');
    }
    
    /**
     * @depends testRunThrowsAfterThrownExceptionFromScheduleCallback
     * @expectedException \Icicle\Loop\Exception\RunningException
     */
    public function testRunThrowsExceptionWhenAlreadyRunning()
    {
        $callback = function () {
            $this->loop->run();
        };
        
        $this->loop->schedule($callback);
        
        $this->loop->run();
    }
    
    /**
     * @depends testSchedule
     */
    public function testStop()
    {
        $this->loop->schedule([$this->loop, 'stop']);
        
        $this->assertSame(true, $this->loop->run());
    }
    
    public function testCreateImmediate()
    {
        $immediate = $this->loop->immediate($this->createCallback(1));
        
        $this->assertInstanceOf('Icicle\Loop\Events\ImmediateInterface', $immediate);
        
        $this->assertTrue($immediate->isPending());
        
        $this->loop->tick(false); // Should invoke immediate callback.
    }

    /**
     * @depends testCreateImmediate
     */
    public function testOneImmediatePerTick()
    {
        $immediate1 = $this->loop->immediate($this->createCallback(1));
        $immediate2 = $this->loop->immediate($this->createCallback(1));
        $immediate3 = $this->loop->immediate($this->createCallback(0));
        
        $this->loop->tick(false);
        
        $this->assertFalse($immediate1->isPending());
        $this->assertTrue($immediate2->isPending());
        $this->assertTrue($immediate3->isPending());
        
        $this->loop->tick(false);
        
        $this->assertFalse($immediate2->isPending());
        $this->assertTrue($immediate3->isPending());
    }

    /**
     * @depends testCreateImmediate
     */
    public function testExecuteImmediate()
    {
        $immediate = $this->loop->immediate($this->createCallback(3));

        $this->loop->tick(false);

        $immediate->execute();

        $this->loop->tick(false);

        $immediate->execute();

        $this->loop->tick(false);
    }

    /**
     * @depends testCreateImmediate
     */
    public function testCancelImmediate()
    {
        $immediate = $this->loop->immediate($this->createCallback(0));

        $immediate->cancel();

        $this->assertFalse($immediate->isPending());

        $this->loop->tick(false);
    }
    
    /**
     * @depends testCreateImmediate
     * @expectedException \Icicle\Loop\Exception\LogicException
     */
    public function testRunThrowsAfterThrownExceptionFromImmediateCallback()
    {
        $exception = new LogicException();
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->will($this->throwException($exception));
        
        $immediate = $this->loop->immediate($callback);
        
        try {
            $this->loop->run(); // Exception should be thrown from loop.
        } catch (Exception $e) {
            $this->assertSame($exception, $e);
            $this->assertFalse($this->loop->isRunning()); // Loop should report that it has stopped.
            throw $e;
        }
        
        $this->fail('Loop should not catch exceptions thrown from immediate callbacks.');
    }
    
    /**
     * @depends testNoBlockingOnEmptyLoop
     */
    public function testCreateTimer()
    {
        $timer = $this->loop->timer(self::TIMEOUT, false, $this->createCallback(1));
        
        $this->assertTrue($timer->isPending());
        
        $this->assertRunTimeBetween([$this->loop, 'run'], self::TIMEOUT - self::RUNTIME, self::TIMEOUT + self::RUNTIME);
    }
    
    /**
     * @depends testCreateTimer
     */
    public function testOverdueTimer()
    {
        $timer = $this->loop->timer(self::TIMEOUT, false, $this->createCallback(1));
        
        usleep(self::TIMEOUT * 3 * self::MICROSEC_PER_SEC);
        
        $this->assertRunTimeLessThan([$this->loop, 'run'], self::RUNTIME);
    }
    
    /**
     * @depends testNoBlockingOnEmptyLoop
     */
    public function testUnreferenceTimer()
    {
        $timer = $this->loop->timer(self::TIMEOUT, false, $this->createCallback(0));
        
        $timer->unreference();
        
        $this->assertTrue($this->loop->isEmpty());
        
        $this->assertRunTimeLessThan([$this->loop, 'run'], self::TIMEOUT);
    }
    
    /**
     * @depends testUnreferenceTimer
     */
    public function testReferenceTimer()
    {
        $timer = $this->loop->timer(self::TIMEOUT, false, $this->createCallback(1));
        
        $timer->unreference();
        $timer->reference();
        
        $this->assertFalse($this->loop->isEmpty());
        
        $this->assertRunTimeGreaterThan([$this->loop, 'run'], self::TIMEOUT - self::RUNTIME);
    }
    
    /**
     * @depends testCreateTimer
     */
    public function testCreatePeriodicTimer()
    {
        $timer = $this->loop->timer(self::TIMEOUT, true, $this->createCallback(2));
        
        $this->assertTrue($timer->isPending());
        
        usleep(self::TIMEOUT * self::MICROSEC_PER_SEC);
        
        $this->loop->tick(true);
        
        usleep(self::TIMEOUT * self::MICROSEC_PER_SEC);
        
        $this->loop->tick(true);
    }
    
    /**
     * @depends testNoBlockingOnEmptyLoop
     * @depends testCreatePeriodicTimer
     */
    public function testStopTimer()
    {
        $timer = $this->loop->timer(self::TIMEOUT, true, $this->createCallback(1));
        
        $this->assertTrue($timer->isPending());
        
        usleep(self::TIMEOUT * self::MICROSEC_PER_SEC);
        
        $this->loop->tick(false);
        
        $timer->stop();
        
        $this->assertFalse($timer->isPending());

        $timer = $this->loop->timer(self::TIMEOUT, false, $this->createCallback(1));

        $this->assertTrue($timer->isPending());

        usleep(self::TIMEOUT * self::MICROSEC_PER_SEC);
        
        $this->loop->run();
    }
    
    /**
     * @depends testStopTimer
     * @depends testCreatePeriodicTimer
     */
    public function testTimerWithSelfStop()
    {
        $iterations = 3;
        
        $callback = $this->createCallback($iterations);
        $callback->method('__invoke')
                 ->will($this->returnCallback(function () use (&$timer, $iterations) {
                     static $count = 0;
                     ++$count;
                     if ($iterations === $count) {
                         $timer->stop();
                    }
                 }));
        
        $timer = $this->loop->timer(self::TIMEOUT, true, $callback);
        
        $this->loop->run();
    }

    /**
     * @depends testStopTimer
     */
    public function testStartTimer()
    {
        $timer = $this->loop->timer(self::TIMEOUT, false, $this->createCallback(1));

        $timer->stop();

        usleep(self::TIMEOUT * self::MICROSEC_PER_SEC);

        $this->loop->tick(false);

        $timer->start();

        $this->assertTrue($timer->isPending());

        usleep(self::TIMEOUT * self::MICROSEC_PER_SEC);

        $this->loop->run();
    }

    /**
     * @depends testStartTimer
     */
    public function testTimerImmediateRestart()
    {
        $timer = $this->loop->timer(self::TIMEOUT, false, $this->createCallback(1));

        $timer->stop();
        $timer->start();

        $this->assertTrue($timer->isPending());

        usleep(self::TIMEOUT * self::MICROSEC_PER_SEC);

        $this->loop->run();
    }

    /**
     * @medium
     * @depends testCreateTimer
     * @expectedException \Icicle\Loop\Exception\LogicException
     */
    public function testRunThrowsAfterThrownExceptionFromTimerCallback()
    {
        $exception = new LogicException();
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->will($this->throwException($exception));
        
        $timer = $this->loop->timer(self::TIMEOUT, false, $callback);
        
        try {
            $this->loop->run(); // Exception should be thrown from loop.
        } catch (Exception $e) {
            $this->assertSame($exception, $e);
            $this->assertFalse($this->loop->isRunning()); // Loop should report that it has stopped.
            throw $e;
        }
        
        $this->fail('Loop should not catch exceptions thrown from timer callbacks.');
    }
    
    /**
     * @requires extension pcntl
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
        
        $signal = $this->loop->signal(SIGUSR1, $callback1);
        $this->assertTrue($signal->isEnabled());
        $signal = $this->loop->signal(SIGUSR2, $callback2);
        $this->assertTrue($signal->isEnabled());
        $signal = $this->loop->signal(SIGUSR1, $callback3);
        $this->assertTrue($signal->isEnabled());

        posix_kill($pid, SIGUSR1);
        posix_kill($pid, SIGUSR2);
        
        $this->loop->tick(false);
    }
    
    /**
     * @depends testSignal
     */
    public function testQuitSignalWithListener()
    {
        $pid = posix_getpid();
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($this->identicalTo(SIGQUIT));
        
        $signal = $this->loop->signal(SIGQUIT, $callback);
        $this->assertTrue($signal->isEnabled());

        posix_kill($pid, SIGQUIT);
        
        $this->loop->tick(false);
    }
    
    /**
     * @medium
     * @depends testSignal
     */
    public function testQuitSignalWithNoListeners()
    {
        $pid = posix_getpid();
        
        $callback = function () use ($pid) {
            posix_kill($pid, SIGQUIT);
            $this->loop->timer(10, false, function () {}); // Keep loop alive until signal arrives.
        };
        
        $this->loop->schedule($callback);
        
        $this->assertSame(true, $this->loop->run());
    }
    
    /**
     * @medium
     * @depends testSignal
     */
    public function testTerminateSignal()
    {
        $pid = posix_getpid();
        
        $callback = function ($signo) {
            $this->assertSame(SIGTERM, $signo);
        };
        
        $signal = $this->loop->signal(SIGTERM, $callback);
        
        $callback = function () use ($pid) {
            posix_kill($pid, SIGTERM);
            $this->loop->timer(10, false, function () {}); // Keep loop alive until signal arrives.
        };
        
        $this->loop->schedule($callback);
        
        $this->assertSame(true, $this->loop->run());
    }
    
    /**
     * @medium
     * @depends testSignal
     * @runInSeparateProcess
     */
    public function testChildSignal()
    {
        $callback = function ($signo) {
            $this->loop->stop();
            $pid = pcntl_wait($status, WNOHANG);
            $this->assertSame(SIGCHLD, $signo);
            $this->assertInternalType('integer', $pid);
            $this->assertInternalType('integer', $status);
        };
        
        $signal = $this->loop->signal(SIGCHLD, $callback);
        
        $fd = [
            ['pipe', 'r'], // stdin
            ['pipe', 'w'], // stdout
            ['pipe', 'w'], // stderr
        ];
        
        proc_open('sleep 1', $fd, $pipes);

        $this->loop->timer(10, false, function () {}); // Keep loop alive until signal arrives.
        
        $this->loop->run();
    }
    
    /**
     * @depends testSignal
     */
    public function testDisableSignal()
    {
        $pid = posix_getpid();

        $callback1 = $this->createCallback(2);
        $callback2 = $this->createCallback(1);

        $signal1 = $this->loop->signal(SIGUSR1, $callback1);
        $signal2 = $this->loop->signal(SIGUSR2, $callback2);

        posix_kill($pid, SIGUSR1);
        posix_kill($pid, SIGUSR2);

        $this->loop->tick(false);

        $signal2->disable();
        $this->assertFalse($signal2->isEnabled());

        posix_kill($pid, SIGUSR1);
        posix_kill($pid, SIGUSR2);

        $this->loop->tick(false);

        $signal1->disable();
        $this->assertFalse($signal1->isEnabled());

        posix_kill($pid, SIGUSR1);
        posix_kill($pid, SIGUSR2);

        $this->loop->tick(false);
    }

    /**
     * @depends testDisableSignal
     */
    public function testEnableSignal()
    {
        $pid = posix_getpid();

        $callback1 = $this->createCallback(1);
        $callback2 = $this->createCallback(1);

        $signal1 = $this->loop->signal(SIGUSR1, $callback1);
        $signal2 = $this->loop->signal(SIGUSR2, $callback2);

        $signal1->disable();
        $signal2->disable();

        posix_kill($pid, SIGUSR1);
        posix_kill($pid, SIGUSR2);

        $this->loop->tick(false);

        $signal1->enable();
        $signal2->enable();

        $this->assertTrue($signal1->isEnabled());
        $this->assertTrue($signal2->isEnabled());

        posix_kill($pid, SIGUSR1);
        posix_kill($pid, SIGUSR2);

        $this->loop->tick(false);
    }

    /**
     * @depends testSignal
     * @expectedException \Icicle\Loop\Exception\InvalidSignalException
     */
    public function testInvalidSignal()
    {
        $this->loop->signal(-1, $this->createCallback(0));
    }

    /**
     * @depends testCreatePoll
     * @depends testCreateAwait
     * @depends testCreateImmediate
     * @depends testCreateTimer
     * @depends testSchedule
     */
    public function testIsEmpty()
    {
        list($readable, $writable) = $this->createSockets();
        
        $poll = $this->loop->poll($readable, $this->createCallback(1));
        $await = $this->loop->await($writable, $this->createCallback(1));
        $immediate = $this->loop->immediate($this->createCallback(1));
        $timer = $this->loop->timer(self::TIMEOUT, false, $this->createCallback(1));
        
        $this->loop->schedule($this->createCallback(1));
        
        $poll->listen();
        $await->listen();
        
        usleep(self::TIMEOUT * self::MICROSEC_PER_SEC);
        
        $this->loop->tick(false);
        
        $this->assertTrue($this->loop->isEmpty());
    }
    
    /**
     * @depends testCreatePoll
     * @depends testCreateAwait
     * @depends testCreateImmediate
     * @depends testCreatePeriodicTimer
     * @depends testSchedule
     */
    public function testClear()
    {
        list($readable, $writable) = $this->createSockets();
        
        $poll = $this->loop->poll($readable, $this->createCallback(0));
        $await = $this->loop->await($writable, $this->createCallback(0));
        $immediate = $this->loop->immediate($this->createCallback(0));
        $timer = $this->loop->timer(self::TIMEOUT, true, $this->createCallback(0));
        
        $this->loop->schedule($this->createCallback(0));
        $poll->listen(self::TIMEOUT);
        $await->listen(self::TIMEOUT);
        
        $this->loop->clear();
        
        $this->assertTrue($this->loop->isEmpty());
        
        $this->assertFalse($poll->isPending());
        $this->assertFalse($await->isPending());
        $this->assertFalse($timer->isPending());
        $this->assertFalse($immediate->isPending());
        
        $this->assertTrue($poll->isFreed());
        $this->assertTrue($await->isFreed());
        
        $this->loop->tick(false);
    }
    
    /**
     * @depends testCreatePoll
     * @depends testCreateAwait
     * @depends testCreateImmediate
     * @depends testCreatePeriodicTimer
     * @depends testSchedule
     */
    public function testReInit()
    {
        list($readable, $writable) = $this->createSockets();
        
        $poll = $this->loop->poll($readable, $this->createCallback(1));
        $await = $this->loop->await($writable, $this->createCallback(1));
        $immediate = $this->loop->immediate($this->createCallback(1));
        $timer = $this->loop->timer(self::TIMEOUT, false, $this->createCallback(1));
        
        $this->loop->schedule($this->createCallback(1));
        $poll->listen();
        $await->listen();
        
        $this->loop->reInit(); // Calling this function should not cancel any pending events.
        
        usleep(self::TIMEOUT * self::MICROSEC_PER_SEC);
        
        $this->loop->tick(false);
    }
}
