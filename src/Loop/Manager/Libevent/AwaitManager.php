<?php
namespace Icicle\Loop\Manager\Libevent;

use Icicle\Loop\Events\SocketEventInterface;

class AwaitManager extends SocketManager
{
    /**
     * @inheritdoc
     */
    protected function createEvent($base, SocketEventInterface $socket, callable $callback)
    {
        $event = event_new();
        event_set($event, $socket->getResource(), EV_WRITE, $callback, $socket);
        event_base_set($event, $base);
        
        return $event;
    }
}
