<?php

declare(strict_types=1);

namespace Tether\Event;

/** copy, cut, paste. */
class ClipboardEventArgs extends EventArgs
{
    /**
     * @param string       $text  what a paste carries as text (cut at 64 KiB)
     * @param list<string> $types the data types on the clipboard
     */
    public function __construct(
        public readonly string $text = '',
        public readonly array $types = [],
    ) {
    }
}
