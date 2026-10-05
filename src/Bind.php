<?php

namespace Tether;

/**
 * Marks a public property of a component that the browser may set, through Component::bind():
 * the value of a field goes to the property, cast to its type (string, int, float, bool, array
 * of strings or a backed enum, each nullable). Without it the browser can not set the property.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Bind
{
}
