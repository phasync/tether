<?php

namespace Demo;

use Tether\Component;

/** The page: each part a component of its own. */
final class Page extends Component
{
    public string $title = 'Tether';

    /** No error boundary above the page: the tab starts over, with fresh state. */
    public function crash(): void
    {
        throw new \RuntimeException('The demo page crashed on purpose');
    }

    public function render(): string
    {
        return <<<HTML
            <main style="font: 16px system-ui; max-width: 36rem; margin: 2rem auto">
              <h1>{$this->title}</h1>
              {$this->child(Who::class)}
              {$this->child(Counter::class)}
              {$this->child(Clock::class)}
              {$this->child(TodoList::class)}
              {$this->child(Interop::class)}
              {$this->child(Forms::class)}
              {$this->child(Panel::class)}
              <p><button tether-click="crash">Crash the tab</button></p>
            </main>
            HTML;
    }
}
