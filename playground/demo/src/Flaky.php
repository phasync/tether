<?php

namespace Demo;

use Tether\Component;

final class Flaky extends Component
{
    public function breakIt(): void
    {
        throw new \RuntimeException('Flaky broke');
    }

    public function render(): string
    {
        return '<p id="flaky">Flaky works. <button tether-click="breakIt">Break it</button></p>';
    }
}
