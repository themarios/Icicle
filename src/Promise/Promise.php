<?php
namespace Icicle\Promise;

use Exception;
use Icicle\Loop;
use Icicle\Promise\Exception\CancelledException;
use Icicle\Promise\Exception\TimeoutException;
use Icicle\Promise\Exception\TypeException;
use Icicle\Promise\Exception\UnresolvedException;
use Icicle\Promise\Structures\FulfilledPromise;
use Icicle\Promise\Structures\RejectedPromise;
use Icicle\Promise\Structures\ThenQueue;

/**
 * Promise implementation based on the Promises/A+ specification adding support for cancellation.
 *
 * @see http://promisesaplus.com
 */
class Promise implements PromiseInterface
{
    use PromiseTrait;
    
    /**
     * @var \Icicle\Promise\PromiseInterface|null
     */
    private $result;
    
    /**
     * @var \Icicle\Promise\Structures\ThenQueue|null
     */
    private $onFulfilled;
    
    /**
     * @var \Icicle\Promise\Structures\ThenQueue|null
     */
    private $onRejected;
    
    /**
     * @var \Closure|null
     */
    private $onCancelled;
    
    /**
     * @var int
     */
    private $children = 0;
    
    /**
     * @param callable $resolver
     * @param callable|null $onCancelled
     */
    public function __construct(callable $resolver, callable $onCancelled = null)
    {
        $this->onFulfilled = new ThenQueue();
        $this->onRejected = new ThenQueue();
        
        /**
         * Resolves the promise with the given promise or value. If another promise, this promise takes
         * on the state of that promise. If a value, the promise will be fulfilled with that value.
         *
         * @param mixed $value A promise can be resolved with anything other than itself.
         */
        $resolve = function ($value = null) {
            if (null === $this->result) {
                if ($value instanceof PromiseInterface) {
                    $this->result = $value->unwrap();
                } else {
                    $this->result = new FulfilledPromise($value);
                }
                
                if ($this === $this->result) {
                    $this->result = new RejectedPromise(new TypeException('Circular reference in promise resolution.'));
                }
                
                $this->result->done($this->onFulfilled, $this->onRejected);
                
                $this->close();
            }
        };
        
        /**
         * Rejects the promise with the given exception.
         *
         * @param mixed $reason
         */
        $reject = function ($reason = null) {
            if (null === $this->result) {
                $this->result = new RejectedPromise($reason);
                $this->result->done($this->onFulfilled, $this->onRejected);
                
                $this->close();
            }
        };
        
        if (null !== $onCancelled) {
            $this->onCancelled = function (Exception $exception) use ($reject, $onCancelled) {
                try {
                    $onCancelled($exception);
                } catch (Exception $exception) {
                    // Caught exception will now be used to reject promise.
                }
                
                $reject($exception);
            };
        } else {
            $this->onCancelled = $reject;
        }
        
        try {
            $resolver($resolve, $reject);
        } catch (Exception $exception) {
            $reject($exception);
        }
    }
    
    /**
     * The garbage collector does not automatically detect (at least not quickly) the circular references that can be
     * created, so explicitly setting these parameters to null is necessary for proper freeing of memory.
     */
    private function close()
    {
        $this->onFulfilled = null;
        $this->onRejected = null;
        $this->onCancelled = null;
    }
    
    /**
     * {@inheritdoc}
     */
    public function then(callable $onFulfilled = null, callable $onRejected = null)
    {
        if (null !== $this->result) {
            return $this->unwrap()->then($onFulfilled, $onRejected);
        }
        
        ++$this->children;
        
        return new self(
            function ($resolve, $reject) use ($onFulfilled, $onRejected) {
                if (null !== $onFulfilled) {
                    $this->onFulfilled->push(function ($value) use ($resolve, $reject, $onFulfilled) {
                        try {
                            $resolve($onFulfilled($value));
                        } catch (Exception $exception) {
                            $reject($exception);
                        }
                    });
                } else {
                    $this->onFulfilled->push(function () use ($resolve) {
                        $resolve($this->result);
                    });
                }
                
                if (null !== $onRejected) {
                    $this->onRejected->push(function (Exception $exception) use ($resolve, $reject, $onRejected) {
                        try {
                            $resolve($onRejected($exception));
                        } catch (Exception $exception) {
                            $reject($exception);
                        }
                    });
                } else {
                    $this->onRejected->push(function () use ($resolve) {
                        $resolve($this->result);
                    });
                }
            },
            function (Exception $exception) {
                Loop\schedule(function () use ($exception) {
                    if (0 === --$this->children) {
                        $this->cancel($exception);
                    }
                });
            }
        );
    }
    
    /**
     * {@inheritdoc}
     */
    public function done(callable $onFulfilled = null, callable $onRejected = null)
    {
        if (null !== $this->result) {
            $this->unwrap()->done($onFulfilled, $onRejected);
            return;
        }

        if (null !== $onFulfilled) {
            $this->onFulfilled->push($onFulfilled);
        }

        if (null !== $onRejected) {
            $this->onRejected->push($onRejected);
        } else {
            $this->onRejected->push(function (Exception $exception) {
                throw $exception; // Rethrow exception in uncatchable way.
            });
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function cancel($reason = null)
    {
        if (null !== $this->result) {
            $this->unwrap()->cancel($reason);
            return;
        }

        if (!$reason instanceof Exception) {
            $reason = new CancelledException($reason);
        }

        $onCancelled = $this->onCancelled;
        $onCancelled($reason);
    }
    
    /**
     * {@inheritdoc}
     */
    public function timeout($timeout, $reason = null)
    {
        if (null !== $this->result) {
            return $this->unwrap()->timeout($timeout, $reason);
        }
        
        ++$this->children;
        
        return new self(
            function ($resolve) use (&$timer, $timeout, $reason) {
                $timer = Loop\timer($timeout, function () use ($reason) {
                    if (!$reason instanceof Exception) {
                        $reason = new TimeoutException($reason);
                    }
                    $this->cancel($reason);
                });
                
                $onResolved = function () use ($resolve, $timer) {
                    $resolve($this->result);
                    $timer->stop();
                };
                
                $this->onFulfilled->push($onResolved);
                $this->onRejected->push($onResolved);
            },
            function (Exception $exception) use (&$timer) {
                $timer->stop();

                Loop\schedule(function () use ($exception) {
                    if (0 === --$this->children) {
                        $this->cancel($exception);
                    }
                });
            }
        );
    }
    
    /**
     * {@inheritdoc}
     */
    public function delay($time)
    {
        if (null !== $this->result) {
            return $this->unwrap()->delay($time);
        }
        
        ++$this->children;
        
        return new self(
            function ($resolve) use (&$timer, $time) {
                $this->onFulfilled->push(function () use (&$timer, $time, $resolve) {
                    $timer = Loop\timer($time, function () use ($resolve) {
                        $resolve($this->result);
                    });
                });
                
                $this->onRejected->push(function () use ($resolve) {
                    $resolve($this->result);
                });
            },
            function (Exception $exception) use (&$timer) {
                if (null !== $timer) {
                    $timer->stop();
                }

                Loop\schedule(function () use ($exception) {
                    if (0 === --$this->children) {
                        $this->cancel($exception);
                    }
                });
            }
        );
    }
    
    /**
     * {@inheritdoc}
     */
    public function isPending()
    {
        return null === $this->result ?: $this->unwrap()->isPending();
    }
    
    /**
     * {@inheritdoc}
     */
    public function isFulfilled()
    {
        return null !== $this->result ? $this->unwrap()->isFulfilled() : false;
    }
    
    /**
     * {@inheritdoc}
     */
    public function isRejected()
    {
        return null !== $this->result ? $this->unwrap()->isRejected() : false;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getResult()
    {
        if ($this->isPending()) {
            throw new UnresolvedException('The promise is still pending.');
        }
        
        return $this->unwrap()->getResult();
    }
    
    /**
     * {@inheritdoc}
     */
    public function unwrap()
    {
        if (null !== $this->result) {
            while ($this->result instanceof self && null !== $this->result->result) {
                $this->result = $this->result->result;
            }
            
            return $this->result;
        }
        
        return $this;
    }
}
