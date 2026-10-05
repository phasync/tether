<?php

namespace Plain;

use Tether\Bind;
use Tether\Component;
use Tether\Event\ChangeEventArgs;

/** A page with state of its own in the browser: the server renders again, and none of it is lost. */
final class Form extends Component
{
    public int $ticks = 0;

    #[Bind]
    public string $name = '';

    #[Bind]
    public ?int $qty = null;

    public string $typed = '';

    public function tick(): void
    {
        ++$this->ticks;
    }

    public function typed(ChangeEventArgs $e): void
    {
        $this->typed = $e->value;
    }

    public function render(): string
    {
        return <<<HTML
            <main id="form">
              <p>Ticked <b id="ticks">{$this->ticks}</b> times <button id="tick" tether-click="tick">tick</button></p>
              <p>Hello <b id="hello">{$this->e($this->name)}</b>, <b id="amount">{$this->e($this->qty)}</b> <b id="typed">{$this->e($this->typed)}</b></p>
              <input id="name" type="text" {$this->bind('name')}>
              <input id="qty" type="number" {$this->bind('qty')}>
              <input id="args" tether-input="typed">
              <input id="note" name="note" value="">
              <input id="ok" type="checkbox">
              <select id="pick"><option value="a" selected>A</option><option value="b">B</option></select>
              <textarea id="area"></textarea>
              <details id="more"><summary>More</summary>Hidden text</details>
              <p id="mark" class="shown">marked</p>
              <p id="follows" class="n{$this->ticks}">follows the server</p>
              <p id="kept" class="n{$this->ticks}" tether-keep="class">kept</p>
            </main>
            HTML;
    }
}
