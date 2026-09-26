<?php

namespace Demo;

use Tether\Component;

final class About extends Component
{
    public function render(): string
    {
        return '<main style="font: 16px system-ui; max-width: 36rem; margin: 2rem auto"><h1 id="about">About</h1><a href="/app/rooms/1">Back to room 1</a></main>';
    }
}
