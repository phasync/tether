<?php

declare(strict_types=1);

namespace Tether\Event;

/** pointerdown, pointerup, pointermove and the rest of the pointer events. */
class PointerEventArgs extends MouseEventArgs
{
    public function __construct(
        public readonly int $pointerId = 0,
        public readonly string $pointerType = 'mouse',
        public readonly float $width = 1,
        public readonly float $height = 1,
        public readonly float $pressure = 0,
        public readonly float $tiltX = 0,
        public readonly float $tiltY = 0,
        public readonly bool $isPrimary = false,
        mixed ...$mouse,
    ) {
        parent::__construct(...$mouse);
    }
}
