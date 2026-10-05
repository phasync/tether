<?php

namespace Demo;

use Tether\Component;
use Tether\ErrorBoundary;

/** The /interop page: a boundary around Bridge, so that a failing invocation is seen and the tab lives on. */
final class InteropPage extends Component implements ErrorBoundary
{
    public ?string $caught = null;

    public function catch(\Throwable $e): void
    {
        $this->caught = $e->getMessage();
    }

    public function render(): string
    {
        $caught = null === $this->caught ? '' : '<p id="caught">' . htmlspecialchars($this->caught) . '</p>';

        return "<main>{$caught}{$this->child(Bridge::class)}</main>";
    }
}
