<?php

namespace Demo;

use Tether\Component;

final class Counter extends Component
{
    public int $count = 0;

    public function increment(): void
    {
        ++$this->count;
    }

    public function render(): string
    {
        return "<p>Clicked {$this->count} times <button tether-click=\"increment\">+1</button></p>";
    }
}
