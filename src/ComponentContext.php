<?php

namespace Tether;

use mini\Contracts\RequestScopeProviderInterface;
use phasync\CancelledException;
use phasync\Context\ContextInterface;
use phasync\Context\ContextTrait;
use Swerve\Swerve;

/**
 * The phasync context of one component's coroutines: its run(), its event handlers, and every
 * coroutine those start. Unmounting the component cancels them all. For mini they are all the
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

    public function setContextException(\Throwable $exception): void
    {
        // A cancelled coroutine is the component leaving, not a failure
        if (!$exception instanceof CancelledException) {
            Swerve::log()->error('Component {id} failed: {exception}', ['id' => $this->componentId, 'exception' => $exception]);
        }
    }
}
