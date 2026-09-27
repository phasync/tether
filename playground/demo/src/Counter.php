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

    /** Called with an argument from the element: tether-args="[5]" */
    public function add(int $n): void
    {
        $this->count += $n;
    }

    public function render(): string
    {
        return "<p>Clicked {$this->count} times <button tether-click=\"increment\">+1</button> <button tether-click=\"add\" tether-args=\"[5]\">+5</button></p>";
    }
}
