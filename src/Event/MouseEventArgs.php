<?php

declare(strict_types=1);

namespace Tether\Event;

/** click, dblclick, auxclick, contextmenu, mousedown, mouseup, mouseenter, mouseleave, mousemove, mouseover, mouseout. */
class MouseEventArgs extends EventArgs
{
    public function __construct(
        public readonly float $clientX = 0,
        public readonly float $clientY = 0,
        public readonly float $pageX = 0,
        public readonly float $pageY = 0,
        public readonly float $offsetX = 0,
        public readonly float $offsetY = 0,
        public readonly float $screenX = 0,
        public readonly float $screenY = 0,
        public readonly int $button = 0,
        public readonly int $buttons = 0,
        public readonly int $detail = 0,
        public readonly bool $shiftKey = false,
        public readonly bool $ctrlKey = false,
        public readonly bool $altKey = false,
        public readonly bool $metaKey = false,
    ) {
    }
}
