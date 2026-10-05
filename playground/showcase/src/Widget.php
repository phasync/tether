<?php

namespace Showcase;

use Tether\Invokable;

final class Widget extends Section
{
    public const ID    = 'widget';
    public const TITLE = 'A browser-side widget in a hook';
    protected const BLURB = 'TinyBars stands for any third-party widget that owns its DOM. The hook creates it in mounted(), feeds it in updated() and frees it in destroyed(); the widget calls the server with invoke(), and the server calls the widget through browser()->hook().';
    protected const SHOW  = ['add', 'toggle', 'flash', 'picked', 'demo'];
    protected const JS    = ['widget', 'hook'];

    /** @var list<int> */
    private array $values = [4, 7, 3, 8, 5];

    private bool $shown = true;

    private string $picked = '';

    public function add(): void
    {
        $this->values[] = \random_int(1, 9);
        $this->values   = \array_slice($this->values, -9);
    }

    public function toggle(): void
    {
        $this->shown = !$this->shown;
    }

    public function flash(): void
    {
        $this->browser()->hook('Bars')->flash(\random_int(0, \count($this->values) - 1));
    }

    #[Invokable]
    public function picked(int $index): string
    {
        $this->picked = "Bar {$index} is {$this->values[$index]}";

        return $this->picked;
    }

    protected function demo(): string
    {
        $values = $this->e(\json_encode($this->values));
        $flash  = $this->shown ? '<button id="bars-flash" tether-click="flash">Flash a bar from the server</button>' : '';
        $widget = $this->shown
            ? "<div class=\"widget\" tether-hook=\"Bars\" data-values=\"{$values}\"><div class=\"bars\" id=\"bars\" tether-ignore></div></div>"
            : '<p class="muted">The widget is gone.</p>';

        return <<<HTML
            {$widget}
            <p class="row">
              <button id="bars-add" tether-click="add">Add a bar</button>
              {$flash}
              <button id="bars-toggle" tether-click="toggle">Remove or restore</button>
            </p>
            <p class="muted" id="picked">{$this->e($this->picked)}</p>
            <pre class="out" id="hooklog" tether-ignore></pre>
            HTML;
    }
}
