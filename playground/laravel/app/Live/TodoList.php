<?php

namespace App\Live;

final class TodoList extends BladeComponent
{
    /** @var array<int, string> */
    public array $items = [1 => 'Try Tether'];

    public string $draft = '';

    private int $next = 2;

    public function type(string $text): void
    {
        $this->draft = $text;
    }

    public function add(): void
    {
        if ('' !== trim($this->draft)) {
            $this->items[$this->next++] = trim($this->draft);
            $this->draft = '';
        }
    }

    public function remove(int $id): void
    {
        unset($this->items[$id]);
    }
}
