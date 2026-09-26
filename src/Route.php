<?php

namespace Tether;

/**
 * A page of an App: GET requests for $path, relative to where the App is mounted. `{name}`
 * matches one path segment and goes to the method's parameter $name, of its type (int, float,
 * string). A parameter typed ServerRequestInterface gets the request (query parameters).
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Route
{
    public function __construct(public readonly string $path)
    {
    }
}
