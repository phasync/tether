<?php

namespace Demo;

use Tether\Component;

/** Who the session says you are: the same on the first render and live. */
final class Who extends Component
{
    public string $name = '';

    public function mount(): void
    {
        $this->name = $_SESSION['name'] ?? '';
    }

    public function render(): string
    {
        $name = htmlspecialchars($this->name);

        return '' !== $name
            ? "<p>Signed in as <b id=\"who\">{$name}</b></p>"
            : '<form action="/login"><input name="name" placeholder="Your name"> <button>Sign in</button></form>';
    }
}
