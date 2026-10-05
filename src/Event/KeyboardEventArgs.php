<?php

declare(strict_types=1);

namespace Tether\Event;

/** keydown, keyup, keypress. */
class KeyboardEventArgs extends EventArgs
{
    public function __construct(
        public readonly string $key = '',
        public readonly string $code = '',
        public readonly int $location = 0,
        public readonly bool $repeat = false,
        public readonly bool $isComposing = false,
        public readonly bool $shiftKey = false,
        public readonly bool $ctrlKey = false,
        public readonly bool $altKey = false,
        public readonly bool $metaKey = false,
    ) {
    }
}
