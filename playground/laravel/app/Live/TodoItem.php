<?php

namespace App\Live;

final class TodoItem extends BladeComponent
{
    public string $text = '';

    public ?\Closure $onRemove = null;

    /** Its own state: survives the list rendering again */
    public bool $done = false;

    public function toggle(): void
    {
        $this->done = !$this->done;
    }

    public function remove(): void
    {
        ($this->onRemove)();
    }
}
