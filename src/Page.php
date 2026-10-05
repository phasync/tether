<?php

namespace Tether;

/**
 * What a page shows: the root component, its props, the page title and the head. With an App, routes that
 * return the same root class with other props keep the tab's components across navigation
 * (only what the new props change renders); another root class replaces them.
 */
final class Page
{
    /**
     * @param class-string<Component> $class
     * @param array<string, mixed>    $props
     * @param string                  $head  HTML for the head of the default document (not of a Tether::from() shell):
     *                                       the application's styles, and its scripts (defer, to run after Tether's: hooks)
     */
    public function __construct(
        public readonly string $class,
        public readonly array $props = [],
        public readonly string $title = '',
        public readonly string $head = '',
    ) {
    }
}
