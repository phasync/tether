<?php

declare(strict_types=1);

namespace Tether\Event;

/** intersect: the element came into, or left, view. */
class IntersectEventArgs extends EventArgs
{
    public function __construct(
        public readonly bool $isIntersecting = false,
        public readonly float $ratio = 0,
    ) {
    }
}
