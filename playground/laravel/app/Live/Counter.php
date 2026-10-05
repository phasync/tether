<?php

namespace App\Live;

final class Counter extends BladeComponent
{
    public int $count = 0;

    public function add(int $n): void
    {
        $this->count += $n;
    }
}
