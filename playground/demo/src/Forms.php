<?php

namespace Demo;

use Tether\Component;

/** What the browser sends for form controls: each handler shows what it got. */
final class Forms extends Component
{
    /** @var array<string, mixed> */
    private array $seen = [];

    public function submitted(array $form): void
    {
        $this->seen['submit'] = $form;
    }

    public function typed(bool|string $value): void
    {
        $this->seen['input'] = $value;
    }

    public function toggled(bool $checked): void
    {
        $this->seen['change'] = $checked;
    }

    public function picked(array $values): void
    {
        $this->seen['pick'] = $values;
    }

    public function render(): string
    {
        $seen = htmlspecialchars(json_encode($this->seen, JSON_THROW_ON_ERROR));
        $checked = ($this->seen['change'] ?? false) ? ' checked' : '';

        return <<<HTML
            <section>
              <form id="form" tether-submit="submitted">
                <input name="note" value="hi">
                <label><input type="checkbox" name="tag" value="a" checked> a</label>
                <label><input type="checkbox" name="tag" value="b" checked> b</label>
                <label><input type="checkbox" name="tag" value="c"> c</label>
                <label><input type="checkbox" name="solo[]" value="x" checked> x</label>
                <button>Send form</button>
              </form>
              <label><input id="box" type="checkbox" tether-input="typed" tether-change="toggled"{$checked}> box</label>
              <select id="multi" multiple tether-change="picked"><option>one</option><option>two</option><option>three</option></select>
              <pre id="seen">{$seen}</pre>
            </section>
            HTML;
    }
}
