<?php

namespace Demo;

use Tether\Component;

/** Items are children, keyed: each keeps its own state when the list changes. */
final class TodoList extends Component
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
            $this->draft                = '';
        }
    }

    public function remove(int $id): void
    {
        unset($this->items[$id]);
    }

    public function render(): string
    {
        $items = '';
        foreach ($this->items as $id => $text) {
            $items .= $this->child(TodoItem::class, ['text' => $text, 'onRemove' => fn () => $this->remove($id)], key: (string) $id);
        }
        $draft = htmlspecialchars($this->draft);

        return <<<HTML
            <section>
              <ul>{$items}</ul>
              <input value="{$draft}" tether-input="type" tether-keydown="add" tether-key="Enter" placeholder="New item, then Enter">
            </section>
            HTML;
    }
}
