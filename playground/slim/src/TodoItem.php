<?php

namespace SlimDemo;

use Tether\Component;

final class TodoItem extends Component
{
    public string $text = '';

    public ?\Closure $onRemove = null;

    /** Its own state: survives the list re-rendering */
    public bool $done = false;

    public function toggle(): void
    {
        $this->done = !$this->done;
    }

    public function remove(): void
    {
        ($this->onRemove)();
    }

    public function render(): string
    {
        $text  = htmlspecialchars($this->text);
        $style = $this->done ? 'text-decoration: line-through' : '';

        return "<li><span class=\"text\" style=\"{$style}\" tether-click=\"toggle\">{$text}</span> <button class=\"remove\" tether-click=\"remove\">×</button></li>";
    }
}
