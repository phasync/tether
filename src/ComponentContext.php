<?php

namespace Tether;

use mini\Contracts\RequestScopeProviderInterface;
use phasync\CancelledException;
use phasync\Context\ContextInterface;
use phasync\Context\ContextTrait;

/**
 * The phasync context of one component's coroutines: its run(), its event handlers, and every
 * coroutine those start. Unmounting the component cancels them all, and a failure of any of
 * them is the component's. For mini they are all the
 * tab's work: they share the tab's request scope, with its request and Scoped services.
 *
 * @internal
 */
final class ComponentContext implements ContextInterface, RequestScopeProviderInterface
{
    use ContextTrait;

    public function __construct(public readonly Circuit $circuit, public readonly string $componentId)
    {
    }

    public function getRequestScope(): object
    {
        return $this->circuit->scope;
    }

    /**
     * A coroutine of the component ended with an exception nobody caught: the component failed,
     * as when its run() or a handler throws. A cancelled coroutine is the component leaving.
     */
    public function setContextException(\Throwable $exception): void
    {
        if (!$exception instanceof CancelledException) {
            $this->circuit->coroutineFailed($this->componentId, $exception);
        }
    }
}
