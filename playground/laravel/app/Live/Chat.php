<?php

namespace App\Live;

use Swerve\Swerve;

/** A room shared by every tab in every worker: swerve's publish/subscribe. */
final class Chat extends BladeComponent
{
    public string $room = '';

    public string $user = '';

    private string $draft = '';

    /** @var list<string> */
    private array $lines = [];

    public function run(): void
    {
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
        if ('' !== trim($this->draft)) {
            Swerve::publish("chat:{$this->room}", "{$this->user}: {$this->draft}");
            $this->draft = '';
        }
    }
}
