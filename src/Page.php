<?php

namespace Tether;

/**
 * What an App's route shows: the root component, its props and the page title. Routes that
 * return the same root class with other props keep the tab's components across navigation
 * (only what the new props change renders); another root class replaces them.
 */
final class Page
{
    /**
     * @param class-string<Component> $class
     * @param array<string, mixed>    $props
     */
    public function __construct(
        public readonly string $class,
        public readonly array $props = [],
        public readonly string $title = '',
    ) {
    }
}
