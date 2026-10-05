<?php

declare(strict_types=1);

namespace Tether\Event;

/** drag, dragstart, dragend, dragenter, dragover, dragleave, drop. */
class DragEventArgs extends MouseEventArgs
{
    /**
     * @param list<string>                                       $types the data types being dragged
     * @param list<array{name: string, size: int, type: string}> $files files being dragged in: no contents
     * @param string                                             $text  what a drop carries as text/plain (cut at 4 KiB); the dragged element's id for an internal drag
     */
    public function __construct(
        public readonly array $types = [],
        public readonly string $effectAllowed = '',
        public readonly string $dropEffect = '',
        public readonly array $files = [],
        public readonly string $text = '',
        mixed ...$mouse,
    ) {
        parent::__construct(...$mouse);
    }
}
