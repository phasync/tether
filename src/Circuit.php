<?php

namespace Tether;

use phasync\CancelledException;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Swerve;
use Tether\Event\EventArgs;

/**
 * One browser tab's components: the tree, rendering, events, calls into the browser (Remote),
 * failures, and the components' coroutines.
 *
 * Rendering is decoupled from state changes: requestRender() puts a component in the tab's
 * redraw set and raises the tab's flag. The tab's writer coroutine (run()) wakes, renders every
 * component in the set once, parents first, and sends them as one frame, with the replies to
 * the browser made meanwhile; then it sends nothing more until 1/$maxFps has
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
 * Navigation (a link, back or forward, Component::navigate()) is the writer's too: $resolve
 * gives the URL's page. The same root class takes the new props; another replaces the root; no
 * page (or no $resolve) is a full page load in the browser.
 *
 * What the browser sends is admitted first (admit()): events, navigations and the rest draw
 * from one token bucket (Limits), and an empty bucket is abuse, which closes the connection.
 * Handlers running at once are limited too: past that, an event is refused, and the
 * connection stays.
 *
 * A Circuit that is not live renders once, for the page's first HTML: no coroutines, no events.
 */
final class Circuit
{
    /** @var array<string, Node> by component id */
    private array $nodes = [];

    private int $nextId = 0;

    /** @var array<string, true> component ids marked by requestRender() */
    private array $dirty = [];

    /** The component whose render() is running, for child(). */
    private ?Node $rendering = null;

    /** The coroutine running a render() or mount(): calls into the browser are refused there. Others may call meanwhile. */
    private ?\Fiber $building = null;

    /** A frame is being made and sent. */
    private bool $flushing = false;

    /** Raised when a frame has been sent: for awaitRender(). */
    private \stdClass $flushed;

    private readonly Remote $remote;

    /** @var array<string, true> ids rendered in the patch under way */
    private array $fresh = [];

    private ?Node $root = null;

    /** @var list<array> replies to the browser's calls for the next frame */
    private array $replies = [];

    /** @var list<array{0: Node, 1: \Throwable}> failed handlers and run()s, for the writer */
    private array $failures = [];

    /** @var list<array{0: string, 1: bool}> URLs to navigate to, and whether it is a new history entry */
    private array $navigations = [];

    /** @var list<array{m: string}> events refused without a reply to carry it, for the next frame */
    private array $refused = [];

    /** Handlers queued or running, in all the tab's components. */
    private int $running = 0;

    private float $tokens;

    private float $refilled;

    private float $overrunLogged = 0.0;

    /**
     * @param \Closure(array): void|null      $send   sends a frame to the browser; null when not live
     * @param float                           $maxFps the most frames a second
     * @param \Closure(\Throwable): void|null $crash  told that the tab crashed
     * @param (\Closure(string): array{0: ?Page, 1: string})|null $resolve the page at a URL, and
     *                                                                     the URL after redirects; no page: a full page load
     * @param ServerRequestInterface|null     $request the request the tab belongs to, for Component::request()
     * @param Limits                          $limits  what the browser may ask of the tab
     * @param \Closure(): void|null           $abuse   told when the browser sends more than the limits allow (the connection should close)
     */
    public function __construct(
        private readonly ?\Closure $send = null,
        private readonly float $maxFps = 30,
        private readonly ?\Closure $crash = null,
        private readonly ?\Closure $resolve = null,
        private readonly ?ServerRequestInterface $request = null,
        private readonly Limits $limits = new Limits(),
        private readonly ?\Closure $abuse = null,
    ) {
        $this->tokens   = $limits->burst;
        $this->refilled = \microtime(true);
        $this->flushed  = new \stdClass();
        $this->remote   = new Remote($this, $send, $limits);
    }

    /**
     * Take a token for something the browser sent. False when the bucket was empty: that is
     * abuse (the client keeps to 90% of the rate), and $abuse has been told.
     */
    public function admit(): bool
    {
        $now            = \microtime(true);
        $this->tokens   = \min($this->limits->burst, $this->tokens + ($now - $this->refilled) * $this->limits->eventsPerSecond);
        $this->refilled = $now;
        if ($this->tokens >= 1) {
            --$this->tokens;

            return true;
        }
        Swerve::log()->warning('Tether: the tab sent more than {rate} events a second, and is closed', ['rate' => $this->limits->eventsPerSecond]);
        $this->abuse?->__invoke();

        return false;
    }

    public function request(): ServerRequestInterface
    {
        return $this->request ?? throw new \LogicException('request() needs a tab started by Tether::from(), the middleware or an App');
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
                while (!$this->dirty && !$this->remote->rel && !$this->replies && !$this->failures && !$this->navigations && !$this->refused) {
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
        } catch (\Throwable $e) {
            // A boundary's catch() that fails, a frame that can't be encoded or sent
            $this->fail(null, $e);
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
        $this->remote->close();
    }

    /** The mount frame has been sent: the browser shows the components rendered for it. */
    public function delivered(): void
    {
        $this->shown(\array_keys($this->fresh));
    }

    /** @param list<string> $ids */
    private function shown(array $ids): void
    {
        foreach ($ids as $id) {
            if (isset($this->nodes[$id])) {
                $this->nodes[$id]->shown = true;
            }
        }
        $this->remote->shown();
    }

    /**
     * An event from the browser: queue it for the component's inbox, which calls the handler
     * in a coroutine of the component's and renders the component. An unknown component is one the
     * page no longer shows: ignored. With $reply, the browser waits for the handler to finish
     * (an event it paces, or Tether.invoke()); with $value too it gets the handler's return
     * value, which only a handler marked #[Invokable] may give.
     *
     * $payload is what the browser says about the event: the handler gets it as its last
     * parameter when that is typed EventArgs or a subclass (never otherwise: $args alone fill
     * the other parameters).
     *
     * An event the tab may not have (the bucket is empty, so the connection is closing) or
     * can not take (too many handlers running) is dropped, and refused when there is a reply or
     * the next frame can tell.
     *
     * @throws \InvalidArgumentException no such handler, or arguments it does not take
     */
    public function event(string $id, string $method, array $args, ?int $reply = null, array $payload = [], bool $value = false): void
    {
        if (!$this->admit()) {
            return;
        }
        $node = $this->nodes[$id] ?? null;
        try {
            if (null === $node) {
                throw new \InvalidArgumentException('No component ' . self::printable($id) . ': it has left the page');
            }
            $args = self::bind($node->component, $method, $args, $payload, $value);
        } catch (\InvalidArgumentException $e) {
            if (null !== $reply) {
                $this->reply($reply, error: $e->getMessage());
            } elseif (null !== $node) {
                $this->refuse($method);
            }
            if (null === $node) {
                return;
            }
            throw $e;
        }
        if ($this->running >= $this->limits->running) {
            if (null !== $reply) {
                $this->reply($reply, error: 'The tab has too many events running');
            } else {
                $this->refuse($method);
            }
            if (($now = \microtime(true)) - $this->overrunLogged >= 1) {
                $this->overrunLogged = $now;
                Swerve::log()->warning('Tether: {component}::{method}() refused: {running} events are running in the tab', ['component' => $node->component::class, 'method' => self::printable($method), 'running' => $this->running]);
            }

            return;
        }
        ++$this->running;
        ++$node->pending;
        $node->events->enqueue([$method, $args, $reply, $value]);
        \phasync::raiseFlag($node);
    }

    private function refuse(string $method): void
    {
        $this->refused[] = ['m' => \substr($method, 0, 64)];
        \phasync::raiseFlag($this);
    }

    /**
     * Go to $url: a link, the browser's back or forward ($push false: the browser's history
     * has it already), or Component::navigate().
     */
    public function navigate(string $url, bool $push = true): void
    {
        if (null === $this->send) {
            throw new \LogicException('navigate() is for event handlers and run(): not render() or mount(), and not before the tab is live');
        }
        $this->navigations[] = [$url, $push];
        \phasync::raiseFlag($this);
    }

    /**
     * The browser's answer to an op. One that answers nothing this tab asked is abuse, as an
     * event is.
     *
     * @param array{n?: mixed, m?: mixed, s?: mixed}|null $error
     */
    public function returned(int $id, mixed $value, ?array $error): void
    {
        if (!$this->remote->returned($id, $value, $error)) {
            $this->admit();
        }
    }

    /** @internal see Component::browser() */
    public function browser(Component $component): Browser
    {
        $node = $this->nodes[$component->tetherId ?? ''] ?? throw new \LogicException('browser() is for event handlers and run(): not render() or mount()');

        return $node->browser ??= new Browser($this->remote, $node, fn (\Closure $fn) => $this->start($node, $fn));
    }

    /** @internal see Remote::op() */
    public function assertNotBuilding(): void
    {
        if (null !== $this->building && \Fiber::getCurrent() === $this->building) {
            throw new \LogicException('The browser can be called from event handlers and run(): not render() or mount()');
        }
    }

    /** @internal see Remote::release() */
    public function wake(): void
    {
        \phasync::raiseFlag($this);
    }

    /** @internal see Component::awaitRender() */
    public function awaitRender(Component $component): void
    {
        $this->assertNotBuilding();
        $id = $component->tetherId;
        $this->requestRender($component);
        while (isset($this->nodes[$id]) && ($this->flushing || isset($this->dirty[$id]))) {
            \phasync::awaitFlag($this->flushed);
        }
    }

    /** @internal see Component::requestRender() */
    public function requestRender(Component $component): void
    {
        if (null === $this->send || !isset($this->nodes[$component->tetherId])) {
            return;
        }
        $this->dirty[$component->tetherId] = true;
        \phasync::raiseFlag($this);
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
                        $this->requestRender($parent);
                    }
                };
            }
        }
        $child = isset($node->children[$identity]) ? $this->nodes[$node->children[$identity]] : null;
        if (null === $child) {
            $child                     = $this->create($class, $props, $node);
            $node->children[$identity] = $child->component->tetherId;

            return $this->render($child);
        }
        // Rendered again when marked, given new props, or when its first render failed
        if (self::setProps($child, $props) || isset($this->dirty[$child->component->tetherId]) || '' === $child->html) {
            return $this->render($child);
        }

        return $child->html;
    }

    /**
     * Render the marked components, parents first, and send them in a frame with the replies and
     * the objects the browser may let go of.
     *
     * The failures of handlers and run()s are handled first, here: a coroutine can not unmount
     * the component it belongs to while it runs.
     */
    private function flush(): void
    {
        $this->flushing = true;
        try {
            $this->makeFrame();
        } finally {
            $this->flushing = false;
            \phasync::raiseFlag($this->flushed);
        }
    }

    private function makeFrame(): void
    {
        foreach ($this->failures as [$node, $e]) {
            if (isset($this->nodes[$node->component->tetherId]) && null === $this->fail($node, $e)) {
                $this->failures = [];

                return;
            }
        }
        $this->failures = [];
        $frame          = ['t' => 'frame'];
        if ($this->navigations && !$this->navigateTo($frame)) {
            return;
        }
        $marked = \array_keys($this->dirty);
        \usort($marked, fn ($a, $b) => $this->nodes[$a]->depth <=> $this->nodes[$b]->depth);
        foreach ($marked as $id) {
            // Rendered with its parent already, or unmounted meanwhile
            if (isset($this->dirty[$id], $this->nodes[$id]) && null !== ($patch = $this->patch($this->nodes[$id]))) {
                $frame['patches'][] = $patch;
            }
        }
        if ($this->remote->rel) {
            $frame['rel'] = \array_map(null, \array_keys($this->remote->rel), $this->remote->rel);
            $this->remote->rel = [];
        }
        if ($this->replies) {
            [$frame['replies'], $this->replies] = [$this->replies, []];
        }
        if ($this->refused) {
            [$frame['refused'], $this->refused] = [$this->refused, []];
        }
        if (\count($frame) > 1) {
            ($this->send)($frame);
            foreach ($frame['patches'] ?? [] as $patch) {
                $this->shown($patch['fresh']);
            }
        }
    }

    /**
     * The navigations asked for, in order, into $frame: the last one's URL and title, and a new
     * root's patch. False when the frame is done: a full page load, or a crash.
     */
    private function navigateTo(array &$frame): bool
    {
        [$navigations, $this->navigations] = [$this->navigations, []];
        foreach ($navigations as [$url, $push]) {
            [$page, $url] = null === $this->resolve ? [null, $url] : ($this->resolve)($url);
            if (null === $page || null === $this->root) {
                ($this->send)(['t' => 'frame', 'nav' => ['load' => $url]]);

                return false;
            }
            $frame['nav'] = ['u' => $url, 't' => $page->title, 'p' => $push || ($frame['nav']['p'] ?? false)];
            $old          = $this->root;
            if ($page->class === $old->component::class) {
                try {
                    if (self::setProps($old, $page->props)) {
                        $this->dirty[$old->component->tetherId] = true;
                    }
                } catch (\InvalidArgumentException $e) {
                    $this->fail(null, $e);

                    return false;
                }
                continue;
            }
            try {
                $this->root = $this->create($page->class, $page->props, null);
            } catch (RenderFailure $failure) {
                $this->fail(null, $failure->getPrevious());

                return false;
            }
            $this->unmount($old);
            if (null === ($patch = $this->patch($this->root))) {
                return false;
            }
            // The new root takes the place of the old one's element
            $frame['patches'] = [['id' => $old->component->tetherId] + $patch];
        }

        return true;
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
                return ['id' => $node->component->tetherId, 'html' => $this->render($node), 'fresh' => \array_keys($this->fresh)];
            } catch (RenderFailure $failure) {
                $node = $this->fail($failure->node, $failure->getPrevious(), $skip);
                if (null === $node) {
                    return null;
                }
                $skip[$node->component->tetherId] = true;
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
            $id = $boundary->component->tetherId;
            if ($boundary->component instanceof ErrorBoundary && !isset($skip[$id]) && isset($this->nodes[$id])) {
                Swerve::log()->error('{component} failed, {boundary} catches it: {exception}', ['component' => $node->component::class, 'boundary' => $boundary->component::class, 'exception' => $e]);
                $boundary->component->catch($e);
                $this->requestRender($boundary->component);

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
        $outer          = $this->building;
        $this->building = \Fiber::getCurrent();
        try {
            $component->mount();
        } catch (CancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RenderFailure($node, $e);
        } finally {
            $this->building = $outer;
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
                [$method, $args, $reply, $carry] = $node->events->dequeue();
                $this->start($node, function () use ($node, $method, $args, $reply, $carry) {
                    try {
                        $value = $node->component->$method(...$args);
                        if (null !== $reply) {
                            $carry && \json_encode($value, \JSON_THROW_ON_ERROR);
                            $this->reply($reply, $carry ? $value : null);
                        }
                    } catch (CancelledException $e) {
                        throw $e;
                    } catch (\Throwable $e) {
                        if (null !== $reply) {
                            $this->reply($reply, error: 'The handler failed');
                        }
                        $this->failed($node, $e);

                        return;
                    } finally {
                        // Unmounting has counted the node's handlers out already
                        if ($node->pending > 0) {
                            --$node->pending;
                            --$this->running;
                        }
                    }
                    $this->requestRender($node->component);
                });
            }
            \phasync::awaitFlag($node);
        }
    }

    /** @internal see Component::go() */
    public function go(Component $component, \Closure $fn): \Fiber
    {
        if (null === $this->send || !isset($this->nodes[$component->tetherId])) {
            throw new \LogicException('go() is for event handlers and run(): not render() or mount(), and not before the tab is live');
        }

        return $this->start($this->nodes[$component->tetherId], $fn);
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
            if (($this->nodes[$node->component->tetherId] ?? null) !== $node) {
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
        $outerBuilding   = $this->building;
        $this->rendering = $node;
        $this->building  = \Fiber::getCurrent();
        // Marked again while this waits (mount() of a child, say): rendered again
        unset($this->dirty[$node->component->tetherId]);
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
            $this->building  = $outerBuilding;
        }
        $html = '<' . $m[1] . ' tether-id="' . $node->component->tetherId . '"' . \substr($html, \strlen($m[0]));
        // Children no longer placed leave
        foreach ($node->children as $identity => $childId) {
            if (!isset($node->placed[$identity])) {
                unset($node->children[$identity]);
                $this->unmount($this->nodes[$childId]);
            }
        }
        $node->html = $html;
        $this->fresh[$node->component->tetherId] = true;

        return $html;
    }

    private function unmount(Node $node): void
    {
        foreach ($node->children as $childId) {
            $this->unmount($this->nodes[$childId]);
        }
        $id = $node->component->tetherId;
        unset($this->nodes[$id], $this->dirty[$id]);
        $node->browser?->retire();
        $this->running -= $node->pending;
        $node->pending = 0;
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
            if (!\property_exists($node->component, $name) || !(new \ReflectionProperty($node->component, $name))->isPublic() || 'tetherId' === $name) {
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
     * (none of Component's or ErrorBoundary's), and arguments of its parameters' types. A last
     * parameter typed EventArgs (or a subclass) is not one of them: it gets $payload.
     *
     * With $value, the browser wants the result: the method must be #[Invokable].
     *
     * @return list<mixed> the arguments to call it with
     *
     * @throws \InvalidArgumentException
     */
    private static function bind(Component $component, string $method, array $args, array $payload, bool $value): array
    {
        $name = $component::class . '::' . self::printable($method) . '()';
        if (!\method_exists($component, $method) || \str_starts_with($method, '__') || \method_exists(Component::class, $method) || ($component instanceof ErrorBoundary && 'catch' === \strtolower($method))) {
            throw new \InvalidArgumentException("$name is not an event handler");
        }
        $reflection = new \ReflectionMethod($component, $method);
        if (!$reflection->isPublic() || $reflection->isStatic()) {
            throw new \InvalidArgumentException("$name is not an event handler");
        }
        if ($value && !$reflection->getAttributes(Invokable::class)) {
            throw new \InvalidArgumentException("$name is not #[Invokable]: its result is not for the browser");
        }
        $parameters = $reflection->getParameters();
        $required   = $reflection->getNumberOfRequiredParameters();
        $eventClass = null;
        $last       = \end($parameters);
        if ($last && !$last->isVariadic() && ($type = $last->getType()) instanceof \ReflectionNamedType && \is_a($type->getName(), EventArgs::class, true)) {
            $eventClass = $type->getName();
            \array_pop($parameters);
            $required = \min($required, \count($parameters));
        }
        if (!\array_is_list($args) || \count($args) < $required || (!$reflection->isVariadic() && \count($args) > \count($parameters))) {
            throw new \InvalidArgumentException(\sprintf('%s takes %d to %s arguments, not %d', $name, $required, $reflection->isVariadic() ? 'any' : \count($parameters), \count($args)));
        }
        foreach ($args as $i => $value) {
            $parameter = $parameters[\min($i, \count($parameters) - 1)];
            if (!self::accepts($parameter->getType(), $value)) {
                throw new \InvalidArgumentException(\sprintf('%s: argument $%s must be %s, not %s', $name, $parameter->getName(), $parameter->getType(), \get_debug_type($value)));
            }
        }
        if (null !== $eventClass) {
            // Optional parameters between the arguments and the event take their defaults
            for ($i = \count($args); $i < \count($parameters); ++$i) {
                $args[] = $parameters[$i]->getDefaultValue();
            }
            try {
                $args[] = $eventClass::from($payload);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException("$name: " . $e->getMessage(), 0, $e);
            }
        }

        return $args;
    }

    /** Text from the browser, for a message that is logged: short, on one line. */
    private static function printable(string $text): string
    {
        return \addcslashes(\substr($text, 0, 64), "\0..\37\177");
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
