<?php

namespace Demo;

use Tether\Component;

/** Stands in for a voice call: its coroutine keeps counting for as long as it is mounted. */
final class Call extends Component
{
    public int $seconds = 0;

    private string $since = '';

    public function mount(): void
    {
        $this->since = bin2hex(random_bytes(4));
    }

    public function run(): void
    {
        while (true) {
            \phasync::sleep(0.2);
            ++$this->seconds;
            $this->requestRender();
        }
    }

    public function render(): string
    {
        return "<p id=\"call\" data-instance=\"{$this->since}\">In a call for {$this->seconds} ticks</p>";
    }
}
