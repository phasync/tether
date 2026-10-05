<?php

namespace Tether;

/**
 * Marks a handler that does not render its component when it returns: it changed nothing the
 * page shows (it logged, saved a draft, told the server where the pointer is). The component
 * still renders when something else asked it to, with requestRender() or another handler.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class NoRender
{
}
