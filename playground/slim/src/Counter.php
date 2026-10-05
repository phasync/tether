<?php

namespace SlimDemo;

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
        return "<main><h1>Counter</h1><p>Clicked <b id=\"count\">{$this->count}</b> times <button id=\"inc\" tether-click=\"increment\">+1</button></p></main>";
    }
}
