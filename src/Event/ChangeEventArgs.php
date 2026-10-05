<?php

declare(strict_types=1);

namespace Tether\Event;

/**
 * input and change. $value is the field's value as the handler's value parameter gets it; on a
 * form it is null and $fields holds the form's fields.
 */
class ChangeEventArgs extends EventArgs
{
    /**
     * @param string|int|float|bool|array<mixed>|null $value
     * @param array<string, mixed>                    $fields
     */
    public function __construct(
        public readonly string|int|float|bool|array|null $value = null,
        public readonly string $name = '',
        public readonly string $inputType = '',
        public readonly array $fields = [],
    ) {
    }
}
