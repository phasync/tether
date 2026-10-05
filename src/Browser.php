<?php

namespace Tether;

/**
 * The browser of one component, for the server to call, in the way of V8Js: a call waits for its
 * answer, and only the calling coroutine is suspended.
 *
 * ```php
 * $width = $this->browser()->window->innerWidth;
 * \phasync::await($this->browser()->call('navigator.clipboard.writeText', $text));   // a Promise is not awaited by itself
 * $ctx = $this->browser()->executeString('return document.getElementById("canvas").getContext("2d");');
 * $ctx->fillStyle = 'rgb(200, 0, 0)';
 * $ctx->fillRect(10, 10, 50, 50);
 * ```
 *
 * What JSON can carry (null, booleans, numbers, strings, plain objects and arrays) comes back as
 * a value, objects as associative arrays. Anything else (a DOM node, a function, a Promise, a
 * Canvas context) comes back as a JsObject, which stands for it in the browser; an object may
 * be given back as an argument. A Promise is not awaited: it is a JsPromise, for
 * `phasync::await($promise, 5.0)` or `then()`. Two promises started and awaited later run
 * concurrently.
 *
 * A JavaScript error, or a rejected Promise, is a JsException. An answer that takes longer than
 * Limits::$callTimeout (or the time Browser::within() gives) is a JsTimeoutException; the tab
 * closing cancels a call that waits. Every call is a round trip to the browser: for loops, write
 * a function in a hook or module and call that once.
 *
 * Names and code are the server's own: never take them from input. The browser can only be
 * asked for what is on the page (window, document, hooks, modules): it runs nothing the server
 * did not send. Objects belong to the component: they go stale when it leaves the page.
 *
 * Calls come from event handlers, run() and the component's coroutines: not render() or mount().
 */
final class Browser
{
    /** The browser's `window`. */
    public readonly JsObject $window;

    /** The browser's `document`. */
    public readonly JsObject $document;

    /** @var array<int, \WeakReference<JsObject>> the objects of this component that are alive */
    private array $objects = [];

    /** @var \WeakMap<\Fiber, float> seconds to wait, in coroutines inside within() */
    private \WeakMap $timeouts;

    private bool $retired = false;

    /** @internal see Component::browser() */
    public function __construct(private readonly Remote $remote, public readonly Node $node, private readonly \Closure $start)
    {
        $this->window   = new JsObject($this, 0, 0);
        $this->document = new JsObject($this, 1, 0);
        $this->timeouts = new \WeakMap();
    }

    /**
     * Call a function by its path from window, with the object it is read from as `this`:
     * `call('navigator.clipboard.writeText', $text)`. A function that returns a Promise gives a
     * JsPromise.
     *
     * @throws JsException
     */
    public function call(string $path, mixed ...$args): mixed
    {
        return $this->remote->op($this, 'path', ['p' => $path, 'a' => \array_values($args)]);
    }

    /**
     * Run JavaScript, the body of a function, and return what it returns (a Promise is not
     * awaited). $vars are its named parameters: values only, so that no value is ever code.
     *
     * The code is yours and constant: never put input into it, pass it in $vars. Needs the page to
     * allow 'unsafe-eval' (a CSP that does not gives a JsException named EvalError); every
     * other call works under a strict CSP.
     *
     * ```php
     * $element = $browser->executeString('return document.getElementById(id);', ['id' => $id]);
     * ```
     *
     * @param array<string, mixed> $vars
     *
     * @throws \InvalidArgumentException a name that is not a JavaScript identifier
     * @throws JsException
     */
    public function executeString(string $code, array $vars = []): mixed
    {
        foreach ($vars as $name => $_) {
            if (!\preg_match('/^[A-Za-z_$][\w$]*$/', (string) $name)) {
                throw new \InvalidArgumentException('Not a JavaScript variable name: ' . \json_encode($name));
            }
        }

        return $this->remote->op($this, 'eval', ['p' => $code, 'n' => \array_map('strval', \array_keys($vars)), 'a' => \array_values($vars)]);
    }

    /**
     * `new Audio($url)`: a constructor by its path from window.
     *
     * @throws JsException
     */
    public function new(string $class, mixed ...$args): mixed
    {
        return $this->remote->op($this, 'new', ['p' => $class, 'a' => \array_values($args)]);
    }

    /**
     * `import($url)`: a JavaScript module, loaded when first asked for; the module's namespace.
     * The URL is yours, not input.
     *
     * @throws JsException
     */
    public function import(string $url): JsObject
    {
        return $this->remote->op($this, 'import', ['p' => $url]);
    }

    /**
     * The live instance of a hook (Tether.hook()) in this component: its element is the component's
     * or inside it. Waits for `mounted()` when that returns a Promise.
     *
     * @throws JsException no such hook on the page
     */
    public function hook(string $name): JsObject
    {
        return $this->remote->op($this, 'hook', ['c' => $this->node->component->tetherId, 'p' => $name]);
    }

    /**
     * The first element of this component matching a CSS selector, or the component's own element
     * without one. Null when there is none. An element can be replaced by a render: ask again where
     * it is used, rather than keeping the object.
     *
     * @throws JsException
     */
    public function find(?string $selector = null): ?JsObject
    {
        return $this->remote->op($this, 'find', ['c' => $this->node->component->tetherId, 'p' => $selector]);
    }

    /**
     * The element of this component marked `tether-ref="$name"`: the way to name an element for the
     * server. Null when there is none.
     *
     * @throws \InvalidArgumentException a name with more than letters, digits, `-` and `_`
     * @throws JsException
     */
    public function ref(string $name): ?JsObject
    {
        if (!\preg_match('/^[\w-]+$/', $name)) {
            throw new \InvalidArgumentException('Not a tether-ref name: ' . \json_encode($name));
        }

        return $this->find("[tether-ref=\"{$name}\"]");
    }

    /**
     * Call $fn, whose calls to the browser wait $seconds for an answer, not the limit's default:
     * for what waits for the user, as `confirm()` does.
     *
     * ```php
     * $sure = $browser->within(120.0, fn () => $browser->call('confirm', 'Delete?'));
     * ```
     */
    public function within(float $seconds, \Closure $fn): mixed
    {
        $fiber    = \Fiber::getCurrent();
        $previous = $this->timeouts[$fiber] ?? null;
        $this->timeouts[$fiber] = $seconds;
        try {
            return $fn();
        } finally {
            null === $previous ? $this->timeouts->offsetUnset($fiber) : $this->timeouts[$fiber] = $previous;
        }
    }

    /** @internal */
    public function remote(): Remote
    {
        return $this->remote;
    }

    /** @internal */
    public function timeout(): ?float
    {
        return $this->timeouts[\Fiber::getCurrent()] ?? null;
    }

    /** @internal */
    public function assertCurrent(): void
    {
        if ($this->retired) {
            throw new JsStaleException('The component has left the page');
        }
    }

    /** @internal a coroutine of the component's */
    public function start(\Closure $fn): \Fiber
    {
        return ($this->start)($fn);
    }

    /** @internal an answer from the browser, with its objects made JsObjects */
    public function decode(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (isset($value['$'])) {
            return $this->object($value);
        }
        foreach ($value as $k => $v) {
            $value[$k] = $this->decode($v);
        }

        return $value;
    }

    /** @internal a JsObject is gone: the browser lets go of it */
    public function forget(int $id, int $refs): void
    {
        unset($this->objects[$id]);
        --$this->remote->handles;
        $this->remote->release($id, $refs);
    }

    /** @internal the component left the page: its objects are stale, and the browser lets go of them */
    public function retire(): void
    {
        $this->retired = true;
        foreach ($this->objects as $id => $ref) {
            $object = $ref->get();
            $this->remote->release($id, $object->retire());
            --$this->remote->handles;
        }
        $this->objects = [];
    }

    private function object(array $marker): JsObject
    {
        $id   = $marker['id'] ?? null;
        $kind = $marker['k'] ?? null;
        if ('h' !== $marker['$'] || !\is_int($id) || !\in_array($kind, ['o', 'fn', 'p'], true)) {
            throw new JsException('The browser answered with something that is not an object reference', 'TypeError');
        }
        if ($id < 2) {
            return 0 === $id ? $this->window : $this->document;
        }
        if (null !== ($object = ($this->objects[$id] ?? null)?->get())) {
            $object->hold();

            return $object;
        }
        if ($this->remote->full()) {
            $this->remote->release($id, 1);
            throw new JsLimitException('The tab holds too many objects from the browser');
        }
        $object = 'p' === $kind ? new JsPromise($this, $id) : new JsObject($this, $id);
        ++$this->remote->handles;
        $this->objects[$id] = \WeakReference::create($object);

        return $object;
    }
}
