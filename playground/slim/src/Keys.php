<?php

namespace SlimDemo;

use Tether\Component;
use Tether\Event\FocusEventArgs;

/** Hover, focus, a keyboard shortcut and a call into the browser. */
final class Keys extends Component
{
    public bool $hover = false;

    public string $focused = 'nothing';

    public int $shortcuts = 0;

    public string $size = '';

    public function hoverIn(): void
    {
        $this->hover = true;
    }

    public function hoverOut(): void
    {
        $this->hover = false;
    }

    public function focus(FocusEventArgs $e): void
    {
        $this->focused = $e->name;
    }

    public function blur(): void
    {
        $this->focused = 'nothing';
    }

    public function shortcut(): void
    {
        ++$this->shortcuts;
        $this->browser()->ref('search')->focus();
    }

    public function measure(): void
    {
        $this->size = $this->browser()->executeString('return innerWidth + "x" + innerHeight;');
    }

    public function render(): string
    {
        $hover = $this->hover ? 'hovered' : 'not hovered';

        return <<<HTML
            <main tether-on-keydown.document.ctrl.key-k.prevent="shortcut">
              <h1>Keys</h1>
              <p id="box" tether-on-mouseenter="hoverIn" tether-on-mouseleave="hoverOut">{$hover}</p>
              <input id="search" name="search" tether-ref="search" placeholder="Ctrl+K focuses this" tether-on-focus="focus" tether-on-blur="blur">
              <p>Focus is on <b id="focused">{$this->focused}</b>; the shortcut ran <b id="shortcuts">{$this->shortcuts}</b> times</p>
              <button id="measure" tether-click="measure">Ask the browser</button> <b id="size">{$this->size}</b>
            </main>
            HTML;
    }
}
