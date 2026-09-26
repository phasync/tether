<?php

namespace Demo;

use Tether\Component;

final class FastPage extends Component
{
    public int $rate = 50;

    public function render(): string
    {
        return <<<HTML
            <main style="font: 16px system-ui; max-width: 36rem; margin: 2rem auto">
              <h1>{$this->rate} updates per second</h1>
              {$this->child(Fast::class, ['rate' => $this->rate])}
              {$this->child(Counter::class)}
            </main>
            HTML;
    }
}
