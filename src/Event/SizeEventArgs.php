<?php

declare(strict_types=1);

namespace Tether\Event;

/** resize (the window: innerWidth, innerHeight, devicePixelRatio) and elementresize (width, height). */
class SizeEventArgs extends EventArgs
{
    public function __construct(
        public readonly float $innerWidth = 0,
        public readonly float $innerHeight = 0,
        public readonly float $devicePixelRatio = 1,
        public readonly float $width = 0,
        public readonly float $height = 0,
    ) {
    }
}
