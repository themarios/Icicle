<?php
namespace Icicle\Tests\Loop\Events;

use Icicle\Loop\Events\SocketEvent;
use Icicle\Tests\TestCase;

class SocketEventTest extends TestCase
{
    const TIMEOUT = 0.1;
    
    protected $manager;
    
    public function setUp()
    {
        $this->manager = $this->getMock('Icicle\Loop\Events\Manager\SocketManagerInterface');
    }
    
    public function createSocketEvent($resource, callable $callback)
    {
        return new SocketEvent($this->manager, $resource, $callback);
    }
    
    public function createSockets()
    {
        return stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    }
    
    public function testGetResource()
    {
        list($socket) = $this->createSockets();
        
        $event = $this->createSocketEvent($socket, $this->createCallback(0));
        
        $this->assertSame($socket, $event->getResource());
    }
    
    /**
     * @depends testGetResource
     * @expectedException \Icicle\Loop\Exception\InvalidArgumentException
     */
    public function testInvalidResource()
    {
        $event = $this->createSocketEvent(1, $this->createCallbacK(0));
    }
    
    public function testCall()
    {
        list($socket) = $this->createSockets();
        
        $callback = $this->createCallback(2);
        $callback->method('__invoke')
                 ->with($this->identicalTo($socket), $this->identicalTo(false));
        
        $event = $this->createSocketEvent($socket, $callback);
        
        $event->call(false);
        $event->call(false);
    }
    
    /**
     * @depends testCall
     */
    public function testInvoke()
    {
        list($socket) = $this->createSockets();
        
        $callback = $this->createCallback(2);
        $callback->method('__invoke')
                 ->with($this->identicalTo($socket), $this->identicalTo(false));
        
        $event = $this->createSocketEvent($socket, $callback);
        
        $event(false);
        $event(false);
    }
    
    public function testListen()
    {
        list($socket) = $this->createSockets();
        
        $event = $this->createSocketEvent($socket, $this->createCallback(0));
        
        $this->manager->expects($this->once())
            ->method('listen')
            ->with($this->identicalTo($event));
        
        $event->listen();
    }
    
    /**
     * @depends testListen
     */
    public function testListenWithTimeout()
    {
        list($socket) = $this->createSockets();
        
        $event = $this->createSocketEvent($socket, $this->createCallback(0));

        $this->manager->expects($this->once())
            ->method('listen')
            ->with($this->identicalTo($event), $this->identicalTo(self::TIMEOUT));
        
        $event->listen(self::TIMEOUT);
    }
    
    public function testIsPending()
    {
        list($socket) = $this->createSockets();
        
        $event = $this->createSocketEvent($socket, $this->createCallback(0));
        
        $this->manager->expects($this->once())
            ->method('isPending')
            ->with($this->identicalTo($event))
            ->will($this->returnValue(true));
        
        $this->assertTrue($event->isPending());
    }
    
    public function testFree()
    {
        list($socket) = $this->createSockets();
        
        $event = $this->createSocketEvent($socket, $this->createCallback(0));
        
        $this->manager->expects($this->once())
            ->method('free')
            ->with($this->identicalTo($event))
            ->will($this->returnValue(true));
        
        $this->manager->expects($this->once())
            ->method('isFreed')
            ->with($this->identicalTo($event))
            ->will($this->returnValue(true));
        
        $event->free();
        
        $this->assertTrue($event->isFreed());
    }
    
    public function testCancel()
    {
        list($socket) = $this->createSockets();
        
        $event = $this->createSocketEvent($socket, $this->createCallback(0));
        
        $this->manager->expects($this->once())
            ->method('cancel')
            ->with($this->identicalTo($event));
        
        $event->cancel();
    }
}
