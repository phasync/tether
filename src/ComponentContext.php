<?php

namespace Tether;

use phasync\CancelledException;
use phasync\Context\ContextInterface;
use phasync\Context\ContextTrait;
use Swerve\Swerve;

/**
 * The phasync context of one component's coroutines: its run(), its event handlers, and every
 * coroutine those start. Unmounting the component cancels them all.
 *
 * @internal
 */
final class ComponentContext implements ContextInterface
{
    use ContextTrait;

    public function __construct(public readonly Circuit $circuit, public readonly string $componentId)
    {
    }

    public function setContextException(\Throwable $exception): void
    {
        // A cancelled coroutine is the component leaving, not a failure
        if (!$exception instanceof CancelledException) {
            Swerve::log()->error('Component {id} failed: {exception}', ['id' => $this->componentId, 'exception' => $exception]);
        }
    }
}
