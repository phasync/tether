<?php

namespace Tether;

use phasync\CancelledException;
use Swerve\Swerve;

/**
 * One browser tab's components: the tree, rendering, events, calls to and from the browser,
 * failures, and the components' coroutines.
 *
 * Rendering is decoupled from state changes: stateHasChanged() puts a component in the tab's
 * redraw set and raises the tab's flag. The tab's writer coroutine (run()) wakes, renders every
 * component in the set once, parents first, and sends them as one frame, with the calls to the
 * browser and the replies to it made meanwhile; then it sends nothing more until 1/$maxFps has
 * passed. Whatever changes meanwhile joins the next frame. A slow client makes the send wait,
 * and the set keeps collecting: it gets fewer frames, never a backlog. A child whose parent
 * renders is rendered as part of it, if it was marked or is new; otherwise the parent's frame
 * keeps its last HTML and the browser leaves the child's DOM alone.
 *
 * A component's coroutines (its inbox, run(), handlers, and those it starts with go()) are
 * tracked by the Circuit: unmounting cancels them, its children's first, and a failure of any
 * of them is the component's. They run in the phasync context of the tab's connection, like
 * any coroutine of a request.
 *
 * A failure goes to the nearest ErrorBoundary above the component that failed. With none, the
 * tab crashes: everything is unmounted, and $crash is told (the connection closes, the browser
 * mounts anew). Without $crash, the exception is thrown.
 *
 * A Circuit that is not live renders once, for the page's first HTML: no coroutines, no events.
 */
final class Circuit
{
    /** @var array<string, Node> by component id */
    private array $nodes = [];

    private int $nextId = 0;

    /** @var array<string, true> component ids marked by stateHasChanged() */
    private array $dirty = [];

    /** The component whose render() is running, for child(). */
    private ?Node $rendering = null;

    /** @var array<string, true> ids rendered in the patch under way */
    private array $fresh = [];

    private ?Node $root = null;

    /** @var list<array> calls to the browser for the next frame */
    private array $calls = [];

    /** @var list<array> replies to the browser's calls for the next frame */
    private array $replies = [];

    private int $nextCall = 0;

    /** @var array<int, \stdClass> js() calls waiting for their result, by call id */
    private array $pending = [];

    /** @var list<array{0: Node, 1: \Throwable}> failed handlers and run()s, for the writer */
    private array $failures = [];

    /**
     * @param \Closure(array): void|null      $send   sends a frame to the browser; null when not live
     * @param float                           $maxFps the most frames a second
     * @param \Closure(\Throwable): void|null $crash  told that the tab crashed
     */
    public function __construct(
        private readonly ?\Closure $send = null,
        private readonly float $maxFps = 30,
        private readonly ?\Closure $crash = null,
    ) {
    }

    /**
     * The tab's writer: waits for a state change, renders what changed and sends it, at most
     * $maxFps times a second. Runs until cancelled, which ends it quietly.
     */
    public function run(): void
    {
        $next = 0.0;
        try {
            while (true) {
                while (!$this->dirty && !$this->calls && !$this->replies && !$this->failures) {
                    \phasync::awaitFlag($this);
                }
                $wait = $next - \microtime(true);
                if ($wait > 0) {
                    \phasync::sleep($wait);
                }
                $this->flush();
                $next = \microtime(true) + 1 / $this->maxFps;
            }
        } catch (CancelledException) {
            // How the writer ends: the tab is gone
        }
    }

    /**
     * Mount the page's root component and render it; null when that failed and the tab crashed.
     *
     * @param class-string<Component> $class
     */
    public function mount(string $class, array $props): ?string
    {
        try {
            $this->root = $this->create($class, $props, null);
        } catch (RenderFailure $failure) {
            $this->fail(null, $failure->getPrevious());

            return null;
        }

        return $this->patch($this->root)['html'] ?? null;
    }

    /** Unmount everything: the tab is gone. */
    public function close(): void
    {
        if (null !== $this->root) {
            $root       = $this->root;
            $this->root = null;
            $this->unmount($root);
        }
    }

    /**
     * An event from the browser: queue it for the component's inbox, which calls the handler
     * in a coroutine of the component's and renders the component. An unknown component is one the
     * page no longer shows: ignored. With $reply, the browser waits for the handler's return
     * value (a hook's push()).
     *
     * @throws \InvalidArgumentException no such handler, or arguments it does not take
     */
    public function event(string $id, string $method, array $args, ?int $reply = null): void
    {
        $node = $this->nodes[$id] ?? null;
        try {
            if (null === $node) {
                throw new \InvalidArgumentException("No component $id: it has left the page");
            }
            self::checkCall($node->component, $method, $args);
        } catch (\InvalidArgumentException $e) {
            if (null !== $reply) {
                $this->reply($reply, error: $e->getMessage());
            }
            if (null === $node) {
                return;
            }
            throw $e;
        }
        $node->events->enqueue([$method, $args, $reply]);
        \phasync::raiseFlag($node);
    }

    /** The browser's answer to a js() call. */
    public function returned(int $call, mixed $value, ?string $error): void
    {
        if (isset($this->pending[$call])) {
            $slot        = $this->pending[$call];
            $slot->value = $value;
            $slot->error = $error;
            $slot->done  = true;
            \phasync::raiseFlag($slot);
        }
    }

    /** @internal see Component::stateHasChanged() */
    public function stateHasChanged(Component $component): void
    {
        if (null === $this->send || !isset($this->nodes[$component->id])) {
            return;
        }
        $this->dirty[$component->id] = true;
        \phasync::raiseFlag($this);
    }

    /** @internal see Component::js() */
    public function js(Component $component, string $function, array $args): mixed
    {
        if (null === $this->send || null !== $this->rendering) {
            throw new \LogicException('js() is for event handlers and run(): not render() or mount(), and not before the tab is live');
        }
        \json_encode($args, \JSON_THROW_ON_ERROR);
        $call                  = ++$this->nextCall;
        $this->pending[$call]  = $slot = new \stdClass();
        $this->calls[]         = ['i' => $call, 'c' => $component->id, 'f' => $function, 'a' => $args];
        // After the component's current state: the call goes in the frame that shows it
        $this->stateHasChanged($component);
        \phasync::raiseFlag($this);
        try {
            while (!isset($slot->done)) {
                \phasync::awaitFlag($slot);
            }
        } finally {
            unset($this->pending[$call]);
        }
        if (null !== $slot->error) {
            throw new JsException($slot->error);
        }

        return $slot->value;
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
                        $this->stateHasChanged($parent);
                    }
                };
            }
        }
        $child = isset($node->children[$identity]) ? $this->nodes[$node->children[$identity]] : null;
        if (null === $child) {
            $child                     = $this->create($class, $props, $node);
            $node->children[$identity] = $child->component->id;

            return $this->render($child);
        }
        // Rendered again when marked, given new props, or when its first render failed
        if (self::setProps($child, $props) || isset($this->dirty[$child->component->id]) || '' === $child->html) {
            return $this->render($child);
        }

        return $child->html;
    }

    /**
     * Render the marked components, parents first, and send them in a frame with the calls and
     * replies.
     *
     * The failures of handlers and run()s are handled first, here: a coroutine can not unmount
     * the component it belongs to while it runs.
     */
    private function flush(): void
    {
        foreach ($this->failures as [$node, $e]) {
            if (isset($this->nodes[$node->component->id]) && null === $this->fail($node, $e)) {
                $this->failures = [];

                return;
            }
        }
        $this->failures = [];
        $marked = \array_keys($this->dirty);
        \usort($marked, fn ($a, $b) => $this->nodes[$a]->depth <=> $this->nodes[$b]->depth);
        $frame = ['t' => 'frame'];
        foreach ($marked as $id) {
            // Rendered with its parent already, or unmounted meanwhile
            if (isset($this->dirty[$id], $this->nodes[$id]) && null !== ($patch = $this->patch($this->nodes[$id]))) {
                $frame['patches'][] = $patch;
            }
        }
        if ($this->calls) {
            [$frame['calls'], $this->calls] = [$this->calls, []];
        }
        if ($this->replies) {
            [$frame['replies'], $this->replies] = [$this->replies, []];
        }
        if (\count($frame) > 1) {
            ($this->send)($frame);
        }
    }

    /**
     * Render a component for a patch. When it, or a component it renders, fails, the nearest
     * error boundary above that one catches and renders instead; a boundary that fails to
     * render past it hands on to the next. Null when there was none, and the tab crashed.
     *
     * @return array{id: string, html: string, fresh: list<string>}|null
     */
    private function patch(Node $node): ?array
    {
        $skip = [];
        while (true) {
            $this->fresh = [];
            try {
                return ['id' => $node->component->id, 'html' => $this->render($node), 'fresh' => \array_keys($this->fresh)];
            } catch (RenderFailure $failure) {
                $node = $this->fail($failure->node, $failure->getPrevious(), $skip);
                if (null === $node) {
                    return null;
                }
                $skip[$node->component->id] = true;
            }
        }
    }

    /**
     * $node failed with $e: the nearest error boundary above it, not in $skip, catches it and
     * will render again; or the tab crashes. The boundary, or null after a crash.
     *
     * @param array<string, true> $skip
     */
    private function fail(?Node $node, \Throwable $e, array $skip = []): ?Node
    {
        for ($boundary = $node?->parent; null !== $boundary; $boundary = $boundary->parent) {
            $id = $boundary->component->id;
            if ($boundary->component instanceof ErrorBoundary && !isset($skip[$id]) && isset($this->nodes[$id])) {
                Swerve::log()->error('{component} failed, {boundary} catches it: {exception}', ['component' => $node->component::class, 'boundary' => $boundary->component::class, 'exception' => $e]);
                $boundary->component->catch($e);
                $this->stateHasChanged($boundary->component);

                return $boundary;
            }
        }
        if (null === $this->crash) {
            throw $e;
        }
        $this->close();
        ($this->crash)($e);

        return null;
    }

    private function create(string $class, array $props, ?Node $parent): Node
    {
        if (!\is_subclass_of($class, Component::class)) {
            throw new \InvalidArgumentException("$class is not a Tether component");
        }
        $component = new $class();
        $id        = 'c' . ++$this->nextId;
        $component->attach($this, $id);
        $node = new Node($component, $parent, null === $parent ? 0 : $parent->depth + 1);
        self::setProps($node, $props);
        try {
            $component->mount();
        } catch (CancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RenderFailure($node, $e);
        }
        $this->nodes[$id] = $node;
        if (null !== $this->send) {
            $this->start($node, fn () => $this->inbox($node));
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
            $this->start($node, $node->component->run(...));
        }
        while (true) {
            while (!$node->events->isEmpty()) {
                [$method, $args, $reply] = $node->events->dequeue();
                $this->start($node, function () use ($node, $method, $args, $reply) {
                    try {
                        $value = $node->component->$method(...$args);
                        if (null !== $reply) {
                            \json_encode($value, \JSON_THROW_ON_ERROR);
                            $this->reply($reply, $value);
                        }
                    } catch (CancelledException $e) {
                        throw $e;
                    } catch (\Throwable $e) {
                        if (null !== $reply) {
                            $this->reply($reply, error: $e->getMessage());
                        }
                        $this->failed($node, $e);

                        return;
                    }
                    $this->stateHasChanged($node->component);
                });
            }
            \phasync::awaitFlag($node);
        }
    }

    /** @internal see Component::go() */
    public function go(Component $component, \Closure $fn): \Fiber
    {
        if (null === $this->send || !isset($this->nodes[$component->id])) {
            throw new \LogicException('go() is for event handlers and run(): not render() or mount(), and not before the tab is live');
        }

        return $this->start($this->nodes[$component->id], $fn);
    }

    /**
     * A coroutine of $node's: cancelled when it is unmounted, and its failure is the
     * component's. Cancelled, it ends quietly.
     */
    private function start(Node $node, \Closure $fn): \Fiber
    {
        return \phasync::go(function () use ($node, $fn) {
            // Recorded as it starts, before go() returns: go() may suspend its caller, which
            // may be unmounted meanwhile. Started for a component that left: it doesn't run.
            if (($this->nodes[$node->component->id] ?? null) !== $node) {
                return null;
            }
            $node->fibers[\Fiber::getCurrent()] = true;
            try {
                return $fn();
            } catch (CancelledException) {
                return null;
            } catch (\Throwable $e) {
                $this->failed($node, $e);

                return null;
            }
        });
    }

    /** A coroutine of $node's failed: the writer hands it on. */
    private function failed(Node $node, \Throwable $e): void
    {
        $this->failures[] = [$node, $e];
        \phasync::raiseFlag($this);
    }

    private function reply(int $id, mixed $value = null, ?string $error = null): void
    {
        $this->replies[] = null === $error ? ['r' => $id, 'v' => $value] : ['r' => $id, 'e' => $error];
        \phasync::raiseFlag($this);
    }

    private function render(Node $node): string
    {
        $outer           = $this->rendering;
        $this->rendering = $node;
        $node->positions = [];
        $node->placed    = [];
        try {
            $html = \trim($node->component->render());
            // Exactly one root element, which gets the component's id
            if (!\preg_match('/^<([a-zA-Z][a-zA-Z0-9-]*)/', $html, $m)) {
                throw new \LogicException($node->component::class . '::render() must return exactly one root element');
            }
        } catch (CancelledException|RenderFailure $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RenderFailure($node, $e);
        } finally {
            $this->rendering = $outer;
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
        foreach ($node->fibers as $fiber => $_) {
            if ($fiber !== \Fiber::getCurrent() && !$fiber->isTerminated()) {
                \phasync::cancel($fiber);
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

    /**
     * The browser may call $method with $args: a public method of the component's own class
     * (none of Component's or ErrorBoundary's), and arguments of its parameters' types.
     *
     * @throws \InvalidArgumentException
     */
    private static function checkCall(Component $component, string $method, array $args): void
    {
        $name = $component::class . "::$method()";
        if (!\method_exists($component, $method) || \str_starts_with($method, '__') || \method_exists(Component::class, $method) || ($component instanceof ErrorBoundary && 'catch' === \strtolower($method))) {
            throw new \InvalidArgumentException("$name is not an event handler");
        }
        $reflection = new \ReflectionMethod($component, $method);
        if (!$reflection->isPublic() || $reflection->isStatic()) {
            throw new \InvalidArgumentException("$name is not an event handler");
        }
        $parameters = $reflection->getParameters();
        if (!\array_is_list($args) || \count($args) < $reflection->getNumberOfRequiredParameters() || (!$reflection->isVariadic() && \count($args) > \count($parameters))) {
            throw new \InvalidArgumentException(\sprintf('%s takes %d to %s arguments, not %d', $name, $reflection->getNumberOfRequiredParameters(), $reflection->isVariadic() ? 'any' : \count($parameters), \count($args)));
        }
        foreach ($args as $i => $value) {
            $parameter = $parameters[\min($i, \count($parameters) - 1)];
            if (!self::accepts($parameter->getType(), $value)) {
                throw new \InvalidArgumentException(\sprintf('%s: argument $%s must be %s, not %s', $name, $parameter->getName(), $parameter->getType(), \get_debug_type($value)));
            }
        }
    }

    /** Whether a parameter of $type takes $value, a value decoded from JSON. */
    private static function accepts(?\ReflectionType $type, mixed $value): bool
    {
        if (null === $type) {
            return true;
        }
        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if (self::accepts($member, $value)) {
                    return true;
                }
            }

            return false;
        }
        if (null === $value && $type->allowsNull()) {
            return true;
        }

        return $type instanceof \ReflectionNamedType && match ($type->getName()) {
            'mixed'             => true,
            'int'               => \is_int($value),
            'float'             => \is_int($value) || \is_float($value),
            'string'            => \is_string($value),
            'bool'              => \is_bool($value),
            'true'              => true === $value,
            'false'             => false === $value,
            'array', 'iterable' => \is_array($value),
            default             => false, // objects never come from the browser
        };
    }
}
