<?php

namespace Demo;

use Tether\Component;

/** Its own coroutine updates it every second, for as long as it is on the page. */
final class Clock extends Component
{
    public string $time = '';

    public function run(): void
    {
        while (true) {
            $this->time = date('H:i:s');
            $this->requestRender();
            \phasync::sleep(1);
        }
    }

    public function render(): string
    {
        return "<p>Server time: <strong>{$this->time}</strong></p>";
    }
}
