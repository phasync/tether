<?php

declare(strict_types=1);

namespace Tether\Event;

/**
 * touchstart, touchend, touchcancel, touchmove. Each list has at most 10 touches, each
 * `{identifier, clientX, clientY, pageX, pageY, force}`.
 */
class TouchEventArgs extends EventArgs
{
    /**
     * @param list<array<string, int|float>> $touches
     * @param list<array<string, int|float>> $targetTouches
     * @param list<array<string, int|float>> $changedTouches
     */
    public function __construct(
        public readonly array $touches = [],
        public readonly array $targetTouches = [],
        public readonly array $changedTouches = [],
        public readonly bool $shiftKey = false,
        public readonly bool $ctrlKey = false,
        public readonly bool $altKey = false,
        public readonly bool $metaKey = false,
    ) {
    }
}
