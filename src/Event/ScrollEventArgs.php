<?php

declare(strict_types=1);

namespace Tether\Event;

/** scroll: the page's position when the target is the document, else the element's. */
class ScrollEventArgs extends EventArgs
{
    public function __construct(
        public readonly float $scrollX = 0,
        public readonly float $scrollY = 0,
        public readonly float $scrollTop = 0,
        public readonly float $scrollLeft = 0,
        public readonly float $scrollHeight = 0,
        public readonly float $clientHeight = 0,
        public readonly float $clientWidth = 0,
    ) {
    }
}
