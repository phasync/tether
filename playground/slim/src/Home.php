<?php

namespace SlimDemo;

use Tether\Component;

/** The home page: the pieces on one page, and the user Slim's middleware found. */
final class Home extends Component
{
    public function render(): string
    {
        return <<<HTML
            <main>
              <h1>Tether in Slim</h1>
              {$this->child(Who::class)}
              {$this->child(Counter::class)}
              {$this->child(Todo::class)}
              <p><a href="/chat/lobby">chat</a> <a href="/keys">keys</a></p>
            </main>
            HTML;
    }
}
