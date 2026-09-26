<?php

namespace Tether;

/**
 * A live component: its state is in its properties, render() turns it into HTML, and it lives
 * on the server for as long as it is on the page.
 *
 * - Props: the public properties its parent (or the page) passes, set before each render.
 * - render(): exactly one root element; Tether marks it with the component's id. Children are
 *   placed with child().
 * - run(): optional; runs in a coroutine of its own while the component is on the page, and is
 *   cancelled when it leaves (its parent stops rendering it, or the browser tab closes). Every
 *   coroutine it starts is cancelled with it. Wait with sleep() or phasync::sleep().
 * - stateHasChanged(): render again soon: in the tab's next frame. Called many times in a row,
 *   it still renders once. After an event handler, the component renders by itself.
 * - Event handlers: public methods of the component's own class, called from the browser
 *   (`tether-click="increment"`). Never render(), run(), or those of this class.
 */
abstract class Component
{
    /** Set by the Circuit when the component is mounted. */
    public readonly string $id;

    private ?Circuit $circuit = null;

    abstract public function render(): string;

    public function run(): void
    {
    }

    /** Render this component again soon. */
    final protected function stateHasChanged(): void
    {
        $this->circuit?->stateHasChanged($this);
    }

    /**
     * A child component, in render(): its HTML where it is placed. The child is identified by
     * its class and $key, or its class and its place among the children of that class without
     * a key: give a key to children rendered in a loop, so each keeps its state when the list
     * changes.
     *
     * @param class-string<Component> $class
     * @param array<string, mixed>    $props  public properties of the child
     */
    final protected function child(string $class, array $props = [], ?string $key = null): string
    {
        return $this->circuit->child($this, $class, $props, $key);
    }

    /** @internal */
    final public function attach(Circuit $circuit, string $id): void
    {
        $this->circuit = $circuit;
        $this->id      = $id;
    }
}
