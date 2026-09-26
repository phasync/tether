<?php

namespace Tether;

/**
 * An exception from a component's render() or mount(), carried up through its ancestors'
 * renders to where the Circuit hands it to an error boundary.
 *
 * @internal
 */
final class RenderFailure extends \RuntimeException
{
    public function __construct(public readonly Node $node, \Throwable $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
