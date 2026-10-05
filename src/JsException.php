<?php

namespace Tether;

/**
 * What the browser's JavaScript threw, or a rejected Promise: the error's name, message and
 * stack. Text from the browser is cut at 4 KiB, and is never to be trusted as markup.
 */
class JsException extends \RuntimeException
{
    public function __construct(string $message = '', public readonly string $jsName = 'Error', public readonly string $jsStack = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
