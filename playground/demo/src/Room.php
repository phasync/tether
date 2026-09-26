<?php

namespace Demo;

use Tether\Component;

final class Room extends Component
{
    public int $id = 0;

    public string $draft = '';

    public function type(string $text): void
    {
        $this->draft = $text;
    }

    public function render(): string
    {
        $draft = htmlspecialchars($this->draft);

        return "<section id=\"room\" data-room=\"{$this->id}\"><h2>Room {$this->id}</h2><input id=\"draft\" value=\"{$draft}\" tether-input=\"type\"></section>";
    }
}
