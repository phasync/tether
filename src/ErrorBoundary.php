<?php

namespace Tether;

/**
 * A component that catches what fails below it: an exception from a descendant's render(),
 * mount(), event handler or run(). catch() gets the exception, and the boundary renders again,
 * as it chooses: it may show an error in place of its children, whose coroutines are then
 * cancelled, and render them anew later (a "Try again" handler). Its own failures, and those it
 * fails to render past, go to the next boundary up. With none, the tab starts over: it
 * reconnects and mounts from scratch, as after a restart.
 *
 *     final class Panel extends Component implements ErrorBoundary
 *     {
 *         public ?string $error = null;
 *
 *         public function catch(\Throwable $e): void
 *         {
 *             $this->error = $e->getMessage();
 *         }
 *
 *         public function retry(): void
 *         {
 *             $this->error = null;
 *         }
 *
 *         public function render(): string
 *         {
 *             return null !== $this->error
 *                 ? '<div>Failed. <button tether-click="retry">Try again</button></div>'
 *                 : '<div>' . $this->child(Feed::class) . '</div>';
 *         }
 *     }
 */
interface ErrorBoundary
{
    public function catch(\Throwable $e): void;
}
