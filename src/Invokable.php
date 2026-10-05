<?php

namespace Tether;

/**
 * Marks a handler the browser may call with Tether.invoke() (or a hook's this.invoke()) and read
 * the result of. Without it the call is refused: a public method is an event handler, but its
 * return value does not leave the server.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Invokable
{
}
