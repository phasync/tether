<?php

namespace Demo;

use Tether\Component;
use Tether\ErrorBoundary;

/** An error boundary: when Flaky fails, the panel shows the error instead of it. */
final class Panel extends Component implements ErrorBoundary
{
    public ?string $error = null;

    public function catch(\Throwable $e): void
    {
        $this->error = $e->getMessage();
    }

    public function retry(): void
    {
        $this->error = null;
    }

    public function render(): string
    {
        if (null !== $this->error) {
            $error = htmlspecialchars($this->error);

            return "<section><p id=\"panel-error\">Failed: {$error} <button tether-click=\"retry\">Try again</button></p></section>";
        }

        return "<section>{$this->child(Flaky::class)}</section>";
    }
}
