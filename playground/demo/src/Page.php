<?php

namespace Demo;

use Tether\Component;

/** The page: a counter, a clock, and a todo list, each a component of its own. */
final class Page extends Component
{
    public string $title = 'Tether';

    public function render(): string
    {
        return <<<HTML
            <main style="font: 16px system-ui; max-width: 36rem; margin: 2rem auto">
              <h1>{$this->title}</h1>
              {$this->child(Counter::class)}
              {$this->child(Clock::class)}
              {$this->child(TodoList::class)}
            </main>
            HTML;
    }
}
