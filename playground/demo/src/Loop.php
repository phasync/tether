<?php

namespace Demo;

use Tether\Component;

/** Fails shortly after every mount: the browser must not reconnect at full speed. */
final class Loop extends Component
{
    public function run(): void
    {
        \phasync::sleep(0.05);
        throw new \RuntimeException('The demo loop failed on purpose');
    }

    public function render(): string
    {
        return '<p id="loop">Failing</p>';
    }
}
