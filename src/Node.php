<?php

namespace Tether;

/**
 * A mounted component in a Circuit's tree.
 *
 * @internal
 */
final class Node
{
    /** @var array<string, string> child identity => component id */
    public array $children = [];

    /** @var array<string, int> children placed per class without a key, in the render under way */
    public array $positions = [];

    /** @var array<string, true> identities placed in the render under way */
    public array $placed = [];

    /** @var array<string, mixed> the props last given */
    public array $props = [];

    /** Handlers queued or running: counted in the Circuit's running, and counted out when the node unmounts. */
    public int $pending = 0;

    /** The HTML of the last render. */
    public string $html = '';

    /** The browser has the component's first HTML: calls into it may go. */
    public bool $shown = false;

    public ?Browser $browser = null;

    /** @var \SplQueue<array{0: string, 1: array, 2: ?int, 3: bool}> events for the component's inbox: method, arguments, reply id, whether the reply carries the return value */
    public \SplQueue $events;

    /** @var \WeakMap<\Fiber, true> the component's coroutines: its inbox, run(), handlers, go() */
    public \WeakMap $fibers;

    public function __construct(
        public readonly Component $component,
        public readonly ?Node $parent,
        public readonly int $depth,
    ) {
        $this->events = new \SplQueue();
        $this->fibers = new \WeakMap();
    }
}
