<?php

declare(strict_types=1);

namespace Tether\Event;

/** submit: the form's fields (a repeated name gives an array; files are not sent), and the submitter's name. */
class SubmitEventArgs extends EventArgs
{
    /** @param array<string, mixed> $fields */
    public function __construct(
        public readonly array $fields = [],
        public readonly string $submitter = '',
    ) {
    }
}
