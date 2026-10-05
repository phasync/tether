<?php

declare(strict_types=1);

namespace Tether\Event;

/** focus, blur, focusin, focusout. */
class FocusEventArgs extends EventArgs
{
    /**
     * @param string $relatedId the id of the element focus comes from or goes to ('' when none has one)
     * @param string $name      the focused element's name attribute
     */
    public function __construct(
        public readonly string $relatedId = '',
        public readonly string $name = '',
    ) {
    }
}
