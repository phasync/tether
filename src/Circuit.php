<?php

namespace Tether;

/**
 * One browser tab's components: the tree, rendering, events, and their coroutines.
 *
 * Rendering is batched: update() marks a component, and the marked components render once,
 * parents first, at the next turn of the event loop. A child whose parent renders is rendered
 * as part of it, if it was marked or is new; otherwise the parent's patch keeps its last HTML
 * and the browser leaves the child's DOM alone.
 *
 * Each component has a phasync context of its own (ComponentContext): unmounting cancels its
 * coroutines, its children's first.
 *
 * A Circuit that is not live renders once, for the page's first HTML: no coroutines, no events.
 */
final class Circuit
{
    /** @var array<string, Node> by component id */
    private array $nodes = [];

    private int $nextId = 0;

    /** @var array<string, true> component ids marked by update() */
    private array $dirty = [];

    private bool $scheduled = false;

    /** The component whose render() is running, for child(). */
    private ?Node $rendering = null;

    /** @var array<string, true> ids rendered in the patch under way */
    private array $fresh = [];

    private ?Node $root = null;

    /**
     * @param \Closure(array): void|null $send sends a frame to the browser; null when not live
     */
    public function __construct(private readonly ?\Closure $send = null)
    {
    }

    /**
     * Mount the page's root component and render it.
     *
     * @param class-string<Component> $class
     */
    public function mount(string $class, array $props): string
    {
        $this->root = $this->create($class, $props, null);

        return $this->render($this->root);
    }

    /** Unmount everything: the tab is gone. */
    public function close(): void
    {
        if (null !== $this->root) {
            $this->unmount($this->root);
            $this->root = null;
        }
    }

    /**
     * An event from the browser: call the component's handler in the component's context, then
     * render it. An unknown component is one the page no longer shows: ignored.
     */
    public function event(string $id, string $method, array $args): void
    {
        $node = $this->nodes[$id] ?? null;
        if (null === $node) {
            return;
        }
        $component = $node->component;
        if (!self::isHandler($component, $method)) {
            throw new \InvalidArgumentException(\sprintf('%s has no event handler %s()', $component::class, $method));
        }
        $node->events->enqueue([$method, $args]);
        \phasync::raiseFlag($node);
    }

    /** @internal see Component::update() */
    public function update(Component $component): void
    {
        if (null === $this->send || !isset($this->nodes[$component->id])) {
            return;
        }
        $this->dirty[$component->id] = true;
        if (!$this->scheduled) {
            $this->scheduled = true;
            // After what runs now: updates in the same turn render once
            \phasync::go(function () {
                \phasync::sleep(0);
                $this->flush();
            });
        }
    }

    /** @internal see Component::child() */
    public function child(Component $parent, string $class, array $props, ?string $key): string
    {
        $node = $this->rendering;
        if (null === $node || $node->component !== $parent) {
            throw new \LogicException('child() can only be called from the component\'s own render()');
        }
        $identity = $class . (null !== $key ? ":$key" : '#' . ($node->positions[$class] = ($node->positions[$class] ?? 0) + 1));
        if (isset($node->placed[$identity])) {
            throw new \LogicException(\sprintf('Two children of %s have the key "%s"', $parent::class, $key));
        }
        $node->placed[$identity] = true;
        // A closure passed down is how a child tells its parent something: calling it renders
        // the parent afterwards, as Blazor's EventCallback does
        foreach ($props as $name => $value) {
            if ($value instanceof \Closure) {
                $props[$name] = function (...$args) use ($value, $parent) {
                    try {
                        return $value(...$args);
                    } finally {
                        $this->update($parent);
                    }
                };
            }
        }
        $child                   = isset($node->children[$identity]) ? $this->nodes[$node->children[$identity]] : null;
        if (null === $child) {
            $child                        = $this->create($class, $props, $node);
            $node->children[$identity] = $child->component->id;

            return $this->render($child);
        }
        if (self::setProps($child, $props) || isset($this->dirty[$child->component->id])) {
            return $this->render($child);
        }

        return $child->html;
    }

    /**
     * Render the marked components, parents first, and send the patches.
     */
    private function flush(): void
    {
        $this->scheduled = false;
        $marked          = \array_keys($this->dirty);
        \usort($marked, fn ($a, $b) => $this->nodes[$a]->depth <=> $this->nodes[$b]->depth);
        $patches = [];
        foreach ($marked as $id) {
            // Rendered with its parent already, or unmounted meanwhile
            if (!isset($this->dirty[$id], $this->nodes[$id])) {
                continue;
            }
            $this->fresh = [];
            $html        = $this->render($this->nodes[$id]);
            $patches[]   = ['id' => $id, 'html' => $html, 'fresh' => \array_keys($this->fresh)];
        }
        if ($patches) {
            ($this->send)(['t' => 'patch', 'patches' => $patches]);
        }
    }

    private function create(string $class, array $props, ?Node $parent): Node
    {
        if (!\is_subclass_of($class, Component::class)) {
            throw new \InvalidArgumentException("$class is not a Tether component");
        }
        $component = new $class();
        $id        = 'c' . ++$this->nextId;
        $component->attach($this, $id);
        $node = new Node($component, $parent, null === $parent ? 0 : $parent->depth + 1, null === $this->send ? null : new ComponentContext($this, $id));
        self::setProps($node, $props);
        $this->nodes[$id] = $node;
        if (null !== $node->context) {
            // The one coroutine started with the component's context (a phasync context is used
            // once): the others are started from it, and inherit it
            \phasync::go(fn () => $this->inbox($node), context: $node->context);
        }

        return $node;
    }

    /**
     * A component's first coroutine: starts run(), then starts a coroutine for each event, in
     * the order they came. Cancelled, with all it started, when the component is unmounted.
     */
    private function inbox(Node $node): void
    {
        $component = $node->component;
        if (Component::class !== (new \ReflectionMethod($component, 'run'))->getDeclaringClass()->getName()) {
            \phasync::go($component->run(...));
        }
        while (true) {
            while (!$node->events->isEmpty()) {
                [$method, $args] = $node->events->dequeue();
                \phasync::go(function () use ($component, $method, $args) {
                    $component->$method(...$args);
                    $this->update($component);
                });
            }
            \phasync::awaitFlag($node);
        }
    }

    private function render(Node $node): string
    {
        $outer           = $this->rendering;
        $this->rendering = $node;
        $node->positions = [];
        $node->placed    = [];
        try {
            $html = \trim($node->component->render());
        } finally {
            $this->rendering = $outer;
        }
        // Exactly one root element, which gets the component's id
        if (!\preg_match('/^<([a-zA-Z][a-zA-Z0-9-]*)/', $html, $m)) {
            throw new \LogicException($node->component::class . '::render() must return exactly one root element');
        }
        $html = '<' . $m[1] . ' tether-id="' . $node->component->id . '"' . \substr($html, \strlen($m[0]));
        // Children no longer placed leave
        foreach ($node->children as $identity => $childId) {
            if (!isset($node->placed[$identity])) {
                unset($node->children[$identity]);
                $this->unmount($this->nodes[$childId]);
            }
        }
        $node->html = $html;
        unset($this->dirty[$node->component->id]);
        $this->fresh[$node->component->id] = true;

        return $html;
    }

    private function unmount(Node $node): void
    {
        foreach ($node->children as $childId) {
            $this->unmount($this->nodes[$childId]);
        }
        $id = $node->component->id;
        unset($this->nodes[$id], $this->dirty[$id]);
        if (null !== $node->context) {
            foreach ($node->context->getFibers() as $fiber => $_) {
                if ($fiber !== \Fiber::getCurrent() && !$fiber->isTerminated()) {
                    \phasync::cancel($fiber);
                }
            }
        }
    }

    /**
     * Set a component's props; whether any changed. Closures don't count as a change: a
     * parent creates them anew on every render.
     */
    private static function setProps(Node $node, array $props): bool
    {
        $changed = false;
        foreach ($props as $name => $value) {
            if (!\property_exists($node->component, $name) || !(new \ReflectionProperty($node->component, $name))->isPublic() || 'id' === $name) {
                throw new \InvalidArgumentException(\sprintf('%s has no public property $%s to take the prop', $node->component::class, $name));
            }
            if (!\array_key_exists($name, $node->props) || (!$value instanceof \Closure && $node->props[$name] !== $value)) {
                $changed = true;
            }
            $node->component->$name = $value;
        }
        $node->props = $props;

        return $changed;
    }

    /** A public method of the component's own class, other than render(), run() and magic methods. */
    private static function isHandler(Component $component, string $method): bool
    {
        if (!\method_exists($component, $method) || \str_starts_with($method, '__') || \in_array(\strtolower($method), ['render', 'run'], true)) {
            return false;
        }
        $reflection = new \ReflectionMethod($component, $method);

        return $reflection->isPublic() && !$reflection->isStatic() && Component::class !== $reflection->getDeclaringClass()->getName();
    }
}
