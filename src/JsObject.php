<?php

namespace Tether;

/**
 * An object in the browser (a DOM element, a function, a Canvas context, a module): the
 * server holds this stand-in for it, and uses it as if it were local.
 *
 * ```php
 * $input = $browser->find('input');
 * $input->value = 'Hello';                 // input.value = 'Hello'
 * $input->focus();                         // input.focus()
 * isset($input->checked);                  // input.checked != null
 * $ctx = $browser->find('canvas')->getContext('2d');
 * $ctx->fillRect(10, 10, 50, 50);
 * $text = $input->value();                 // a copy of the object as JSON data
 * ```
 *
 * Every property read, write and call is one round trip to the browser, and what comes back
 * follows the rules of {@see Browser}. The browser keeps the object as long as this stand-in
 * exists (or the component, whichever ends first); one that outlives its component, or an
 * element a render removed, is a JsStaleException when used. Names are the server's: never from input.
 *
 * A method of the JavaScript object named like one of this class's (`call`, `value`, `hold`,
 * `marker`, `retire`) is reached with `call('name', ...)`.
 */
class JsObject implements \ArrayAccess, \Countable, \Stringable
{
    public function __construct(protected readonly Browser $browser, protected readonly int $id, private int $refs = 1)
    {
    }

    /** @throws JsException */
    public function __get(string $name): mixed
    {
        return $this->op('get', ['p' => $name]);
    }

    /** @throws JsException */
    public function __set(string $name, mixed $value): void
    {
        $this->op('set', ['p' => $name, 'a' => [$value]]);
    }

    /** @throws JsException */
    public function __isset(string $name): bool
    {
        return $this->op('has', ['p' => $name]);
    }

    /** @throws JsException */
    public function __unset(string $name): void
    {
        $this->op('del', ['p' => $name]);
    }

    /** @param array<mixed> $args @throws JsException */
    public function __call(string $name, array $args): mixed
    {
        return $this->op('call', ['p' => $name, 'a' => \array_values($args)]);
    }

    /** Call the method $name: for names that are this class's own. @throws JsException */
    public function call(string $name, mixed ...$args): mixed
    {
        return $this->op('call', ['p' => $name, 'a' => \array_values($args)]);
    }

    /** Call a function object itself, `this` being undefined. @throws JsException */
    public function __invoke(mixed ...$args): mixed
    {
        return $this->op('invoke', ['a' => \array_values($args)]);
    }

    /** @throws JsException */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->op('get', ['p' => $offset]);
    }

    /** @throws JsException */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->op('set', ['p' => $offset, 'a' => [$value]]);
    }

    /** @throws JsException */
    public function offsetExists(mixed $offset): bool
    {
        return $this->op('has', ['p' => $offset]);
    }

    /** @throws JsException */
    public function offsetUnset(mixed $offset): void
    {
        $this->op('del', ['p' => $offset]);
    }

    /** The object's `length`. @throws JsException */
    public function count(): int
    {
        return (int) $this->op('get', ['p' => 'length']);
    }

    /** `String($object)`. @throws JsException */
    public function __toString(): string
    {
        return $this->op('str');
    }

    /**
     * A copy of the object as data, the way JSON.stringify() sees it.
     *
     * @throws JsException not serialisable (a cycle, a BigInt)
     */
    public function value(): mixed
    {
        return $this->op('val');
    }

    /** @internal the browser gave the object again */
    public function hold(): void
    {
        ++$this->refs;
    }

    /** @internal how the browser knows this object in a call */
    public function marker(): array
    {
        $this->browser->assertCurrent();

        return ['$' => 'h', 'id' => $this->id];
    }

    /** @internal the component left the page; the references this held */
    public function retire(): int
    {
        $refs       = $this->refs;
        $this->refs = 0;

        return $refs;
    }

    public function __destruct()
    {
        if ($this->refs > 0) {
            $this->browser->forget($this->id, $this->refs);
        }
    }

    private function op(string $op, array $fields = []): mixed
    {
        return $this->browser->remote()->op($this->browser, $op, ['h' => $this->id] + $fields);
    }
}
