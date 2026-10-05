<?php

namespace App\Live;

use Tether\Event\FocusEventArgs;
use Tether\Event\KeyboardEventArgs;
use Tether\Event\PointerEventArgs;

/** Hover, focus and keyboard bindings, with the event's data. */
final class Bindings extends BladeComponent
{
    public bool $hovering = false;

    public bool $focused = false;

    public string $key = '';

    public string $pointer = '';

    public function enter(): void
    {
        $this->hovering = true;
    }

    public function leave(): void
    {
        $this->hovering = false;
    }

    public function move(PointerEventArgs $e): void
    {
        $this->pointer = round($e->offsetX) . ',' . round($e->offsetY);
    }

    public function focus(FocusEventArgs $e): void
    {
        $this->focused = true;
    }

    public function blur(FocusEventArgs $e): void
    {
        $this->focused = false;
    }

    public function keydown(KeyboardEventArgs $e): void
    {
        $this->key = ($e->ctrlKey ? 'Ctrl+' : '') . $e->key;
    }
}
