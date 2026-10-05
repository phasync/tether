<?php

namespace Tether;

use phasync\TimeoutException;

/**
 * A tab's calls into the browser: every operation is one `op` frame sent at once by the calling
 * coroutine, which waits on a slot of its own for the browser's `ret`; only it suspends, and
 * answers are matched by id, in any order.
 *
 * Nothing the browser says is trusted: it can only answer an id this tab issued, once; unknown
 * ids count against the tab's event budget (Circuit::admit()), and text in an answer is cut.
 *
 * @internal see Component::browser()
 */
final class Remote
{
    /** @var array<int, \stdClass> by op id: slots of ops sent, until the browser answers (an op given up on stays, for its late answer) */
    private array $pending = [];

    private int $next = 0;

    /** @var array<int, int> object id => references to give back, for the next frame */
    public array $rel = [];

    /** Objects held by the tab's JsObjects. */
    public int $handles = 0;

    private ?\Closure $send;

    private \stdClass $shown;

    public function __construct(private readonly Circuit $circuit, ?\Closure $send, private readonly Limits $limits)
    {
        $this->send  = $send;
        $this->shown = new \stdClass();
    }

    /** A first HTML of some component reached the browser: ops waiting for it may go. */
    public function shown(): void
    {
        \phasync::raiseFlag($this->shown);
    }

    /**
     * Send an op for $browser and wait for the result: a value, or a JsObject for what JSON can't carry.
     *
     * @param array<string, mixed> $fields the op's own fields; `a` (arguments) is encoded here
     *
     * @throws \LogicException  not live, or from render() or mount()
     * @throws JsException      what the browser threw, or JsTimeoutException, JsLimitException, JsStaleException
     */
    public function op(Browser $browser, string $op, array $fields = [], ?float $timeout = null): mixed
    {
        if (null === $this->send) {
            throw new \LogicException('The browser can be called from event handlers and run(): not before the tab is live');
        }
        $this->circuit->assertNotBuilding();
        $browser->assertCurrent();
        if (\count($this->pending) >= $this->limits->calls) {
            throw new JsLimitException("The tab has {$this->limits->calls} calls to the browser outstanding");
        }
        isset($fields['a']) && $fields['a'] = $this->encode($fields['a']);
        // A component's ops go after the first HTML that shows its elements
        while (!$browser->node->shown) {
            \phasync::awaitFlag($this->shown);
            $browser->assertCurrent();
        }
        $i             = ++$this->next;
        $slot          = new \stdClass();
        $slot->fiber   = \Fiber::getCurrent();
        $this->pending[$i] = $slot;
        $timeout ??= $browser->timeout() ?? $this->limits->callTimeout;
        try {
            ($this->send)(['t' => 'op', 'i' => $i, 'o' => $op] + $fields);
            while (!isset($slot->done)) {
                \phasync::awaitFlag($slot, $timeout);
            }
        } catch (TimeoutException) {
            throw new JsTimeoutException("The browser did not answer {$op} in {$timeout} s");
        } finally {
            if (isset($slot->done)) {
                unset($this->pending[$i]);
            } else {
                $slot->abandoned = true;
            }
        }
        if (null !== $slot->error) {
            $name = self::text($slot->error['n'] ?? '') ?: 'Error';
            $class = 'StaleHandle' === $name ? JsStaleException::class : JsException::class;

            throw new $class(self::text($slot->error['m'] ?? ''), $name, self::text($slot->error['s'] ?? ''));
        }

        return $browser->decode($slot->value);
    }

    /**
     * The browser's answer to an op. False for an id that is not outstanding: not an honest
     * browser's.
     *
     * @param array{n?: mixed, m?: mixed, s?: mixed}|null $error
     */
    public function returned(int $id, mixed $value, ?array $error): bool
    {
        if (null === ($slot = $this->pending[$id] ?? null)) {
            return false;
        }
        if (isset($slot->abandoned)) {
            unset($this->pending[$id]);
            self::walk($value, fn (int $handle) => $this->release($handle, 1));

            return true;
        }
        $slot->value = $value;
        $slot->error = $error;
        $slot->done  = true;
        \phasync::raiseFlag($slot);

        return true;
    }

    /** Whether the tab holds as many objects of the browser's as it may. */
    public function full(): bool
    {
        return $this->handles >= $this->limits->handles;
    }

    /** The browser gets $n references to object $id back. */
    public function release(int $id, int $n): void
    {
        $this->rel[$id] = ($this->rel[$id] ?? 0) + $n;
        $this->circuit->wake();
    }

    /** The tab is gone: coroutines waiting for the browser are cancelled. */
    public function close(): void
    {
        foreach ($this->pending as $slot) {
            if (!isset($slot->done) && !isset($slot->abandoned) && $slot->fiber !== \Fiber::getCurrent() && !$slot->fiber->isTerminated()) {
                \phasync::cancel($slot->fiber);
            }
        }
        $this->send = null;
    }

    /**
     * Arguments for the browser: JsObjects become references to their objects, nothing else
     * changes. Strings are data, never code.
     */
    private function encode(mixed $value): mixed
    {
        if ($value instanceof JsObject) {
            return $value->marker();
        }
        if ($value instanceof \Closure) {
            throw new \InvalidArgumentException('A Closure can not be an argument of a call into the browser');
        }
        if (!\is_array($value)) {
            return $value;
        }
        if (isset($value['$'])) {
            throw new \InvalidArgumentException('An array with the key "$" can not be an argument of a call into the browser');
        }
        foreach ($value as $k => $v) {
            $value[$k] = $this->encode($v);
        }

        return $value;
    }

    /** Call $fn with the id of every object reference in an answer. */
    private static function walk(mixed $value, \Closure $fn): void
    {
        if (!\is_array($value)) {
            return;
        }
        if ('h' === ($value['$'] ?? null)) {
            if (\is_int($value['id'] ?? null) && $value['id'] > 1) {
                $fn($value['id']);
            }

            return;
        }
        foreach ($value as $v) {
            self::walk($v, $fn);
        }
    }

    private static function text(mixed $text): string
    {
        return \is_string($text) ? \substr($text, 0, 4096) : '';
    }
}
