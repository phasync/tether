<?php

namespace Tether;

use Psr\Http\Message\ServerRequestInterface;

/**
 * A live component: its state is in its properties, render() turns it into HTML, and it lives
 * on the server for as long as it is on the page.
 *
 * - Props: the public properties its parent (or the page) passes, set before each render.
 * - mount(): optional; runs once, with the props set, before the first render: load what the
 *   component shows. It runs for the page's first HTML and again when the tab goes live, since
 *   those are two instances; live-only work belongs in run().
 * - render(): exactly one root element; Tether marks it with the component's id. Children are
 *   placed with child().
 * - run(): optional; runs in a coroutine of its own while the component is on the page, and is
 *   cancelled when it leaves (its parent stops rendering it, or the browser tab closes). Wait
 *   with phasync::sleep() (and phasync::readable() / writable() for streams).
 * - go(): start another coroutine of the component's: cancelled when it leaves, and a failure
 *   of it is the component's.
 * - requestRender(): render again soon: in the tab's next frame. Called many times in a row,
 *   it still renders once. After an event handler, the component renders by itself.
 * - Event handlers: public methods of the component's own class, called from the browser
 *   (`tether-click="increment"`, or a hook's push()). Anyone can call them with any JSON
 *   arguments: the arguments must match the parameter types, and the handler checks the rest.
 *   Its return value goes back to the browser's Tether.invoke() when the handler is marked
 *   #[Invokable].
 * - browser(): call the browser, as in V8Js: functions, properties, elements, Promises.
 * - awaitRender(): wait until the browser shows the component's current state.
 * - request(): the request of the tab: the page's request when it renders, the upgrade when live.
 *
 * A failure in any of them goes to the nearest ErrorBoundary above; with none, the tab starts
 * over.
 */
abstract class Component
{
    /**
     * The component's id in its tab (its root element's tether-id), set when it is mounted.
     * Named so as to leave $id to the application's props.
     */
    public readonly string $tetherId;

    private ?Circuit $circuit = null;

    abstract public function render(): string;

    public function mount(): void
    {
    }

    public function run(): void
    {
    }

    /** Render this component again soon. */
    final protected function requestRender(): void
    {
        $this->circuit?->requestRender($this);
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

    /**
     * The browser of this tab, to call from an event handler, run() or another coroutine of the
     * component's (not render() or mount()): see {@see Browser}. A call waits for its answer,
     * and only the calling coroutine waits.
     *
     * ```php
     * $width = $this->browser()->window->innerWidth;
     * ```
     */
    final protected function browser(): Browser
    {
        return $this->circuit->browser($this);
    }

    /**
     * Render this component again soon, and wait until the browser shows it: before a call into
     * the browser that depends on what the render changes (an element it adds, a value it
     * sets). Calls into the browser do not send a pending render.
     */
    final protected function awaitRender(): void
    {
        $this->circuit->awaitRender($this);
    }

    /**
     * Go to $url in this tab, as if a link to it was followed: with an App, a page of the App
     * changes the page over the live connection; any other URL is a full page load. From an
     * event handler or run().
     *
     * With $replace the browser's history entry is replaced instead of a new one added: for a
     * redirect, a filter or a tab that updates the URL as it changes, which the back button
     * should not step through.
     */
    final protected function navigate(string $url, bool $replace = false): void
    {
        $this->circuit->navigate($url, !$replace);
    }

    /**
     * Start a coroutine of this component's, from an event handler, run() or another of its
     * coroutines: it is cancelled when the component leaves the page, and if it throws, the
     * component failed (see ErrorBoundary). A coroutine started with phasync::go() is the tab's
     * work instead, not the component's.
     */
    final protected function go(\Closure $fn): \Fiber
    {
        return $this->circuit->go($this, $fn);
    }

    /**
     * The request this tab belongs to: for a live tab, the WebSocket upgrade, as it was when
     * the tab connected (its cookies, its URL, the attributes the framework's middleware set).
     * Never read its body: that is the connection. Throws when the tab has no request: a first
     * render by Tether::page().
     */
    final protected function request(): ServerRequestInterface
    {
        return $this->circuit->request();
    }

    /** @internal */
    final public function attach(Circuit $circuit, string $id): void
    {
        $this->circuit = $circuit;
        $this->tetherId = $id;
    }
}
