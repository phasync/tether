<?php

namespace Plain;

use Swerve\Swerve;
use Tether\Component;

/** A room shared by every tab, in every worker: swerve's publish/subscribe, in plain sequential code. */
final class Chat extends Component
{
    public string $room = '';

    public string $user = '';

    public bool $live = false;

    private string $draft = '';

    /** @var list<string> */
    private array $lines = [];

    public function run(): void
    {
        $this->live = true;
        $this->requestRender();
        foreach (Swerve::subscribe("chat:{$this->room}") as $line) {
            $this->lines[] = $line;
            $this->requestRender();
        }
    }

    public function type(string $text): void
    {
        $this->draft = $text;
    }

    public function say(): void
    {
        if ('' !== \trim($this->draft)) {
            Swerve::publish("chat:{$this->room}", "{$this->user}: {$this->draft}");
            $this->draft = '';
        }
    }

    public function render(): string
    {
        $lines  = \implode('', \array_map(static fn (string $line) => '<li>' . \htmlspecialchars($line) . '</li>', $this->lines));
        $room   = \htmlspecialchars($this->room);
        $status = $this->live ? 'live' : 'static';
        $path   = \htmlspecialchars($this->request()->getUri()->getPath());
        $draft  = \htmlspecialchars($this->draft);

        return <<<HTML
            <main id="chat" data-room="{$room}" data-status="{$status}">
              <h1>#{$room}</h1>
              <p>{$status}, at <code id="path">{$path}</code></p>
              <ul id="lines">{$lines}</ul>
              <input id="text" value="{$draft}" placeholder="Say something" tether-input="type" tether-keydown.key-enter="say">
            </main>
            HTML;
    }
}
