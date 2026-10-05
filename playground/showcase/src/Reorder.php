<?php

namespace Showcase;

final class Reorder extends Section
{
    public const ID    = 'reorder';
    public const TITLE = 'Drag and drop';
    protected const BLURB = 'Drag a row onto another to take its place: dragstart, dragenter, dragover and drop, with the id of the row as an argument. The arrow buttons do the same by click, for touch screens, where the browser does not drag.';
    protected const SHOW  = ['start', 'enter', 'drop', 'end', 'shift', 'demo'];

    private const LABELS = ['bind' => 'Bind the event', 'write' => 'Write the handler', 'render' => 'Render the state', 'morph' => 'Let the browser morph', 'ship' => 'Ship it'];

    /** @var list<string> */
    private array $order = ['bind', 'write', 'render', 'morph', 'ship'];

    private string $dragging = '';

    private string $over = '';

    public function start(string $id): void
    {
        $this->dragging = $id;
    }

    public function enter(string $id): void
    {
        $this->over = $id;
    }

    public function drop(string $id): void
    {
        if ('' === $this->dragging) {
            return;
        }
        $at = \array_search($id, $this->order, true);
        \array_splice($this->order, \array_search($this->dragging, $this->order, true), 1);
        \array_splice($this->order, $at, 0, [$this->dragging]);
        $this->end();
    }

    public function end(): void
    {
        $this->dragging = $this->over = '';
    }

    public function shift(string $id, int $by): void
    {
        $from = \array_search($id, $this->order, true);
        $to   = \max(0, \min(\count($this->order) - 1, $from + $by));
        \array_splice($this->order, $from, 1);
        \array_splice($this->order, $to, 0, [$id]);
    }

    protected function demo(): string
    {
        $items = '';
        foreach ($this->order as $id) {
            $class  = ($this->dragging === $id ? ' dragging' : '') . ($this->over === $id && $this->dragging !== $id ? ' over' : '');
            $label  = $this->e(self::LABELS[$id]);
            $items .= <<<HTML
                <li class="item{$class}" id="item-{$id}" draggable="true" tether-args='["{$id}"]'
                    tether-on-dragstart="start" tether-on-dragenter="enter" tether-on-dragover.prevent="" tether-on-drop="drop" tether-on-dragend="end" tether-args-dragend="[]">
                  <span class="grip">::</span><span class="label">{$label}</span>
                  <button type="button" aria-label="Up" tether-click="shift" tether-args='["{$id}", -1]'>&#9650;</button>
                  <button type="button" aria-label="Down" tether-click="shift" tether-args='["{$id}", 1]'>&#9660;</button>
                </li>
                HTML;
        }
        $order = \implode(' ', $this->order);

        return "<ol class=\"list\" id=\"list\">{$items}</ol><p class=\"row\">On the server: <code id=\"order\">{$order}</code></p>";
    }
}
