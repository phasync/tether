<?php

declare(strict_types=1);

namespace Tether\Event;

/** wheel: with several wheel events coalesced, the deltas are their sum. */
class WheelEventArgs extends MouseEventArgs
{
    public function __construct(
        public readonly float $deltaX = 0,
        public readonly float $deltaY = 0,
        public readonly float $deltaZ = 0,
        public readonly int $deltaMode = 0,
        mixed ...$mouse,
    ) {
        parent::__construct(...$mouse);
    }
}
