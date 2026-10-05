<?php

namespace SlimDemo;

use Tether\Component;

/** The "user" attribute Slim's middleware put on the request: read when rendered, and read again by a handler on the live tab. */
final class Who extends Component
{
    public string $live = '';

    public function recheck(): void
    {
        $this->live = $this->request()->getAttribute('user');
    }

    public function render(): string
    {
        $user = \htmlspecialchars($this->request()->getAttribute('user'));
        $live = \htmlspecialchars($this->live);

        return <<<HTML
            <p>Signed in as <b id="who">{$user}</b>, live as <b id="who-live">{$live}</b> <button id="recheck" tether-click="recheck">recheck</button></p>
            HTML;
    }
}
