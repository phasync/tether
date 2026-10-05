<?php

namespace Showcase;

use Tether\Event\KeyboardEventArgs;

final class Keys extends Section
{
    public const ID    = 'keys';
    public const TITLE = 'Keyboard';
    protected const BLURB = 'Focus the box and type: keydown, keypress and keyup with the modifier state. The shortcuts are bound to the document with key filters: mod+k opens the palette (Cmd on a Mac, Ctrl elsewhere), Escape closes it.';
    protected const SHOW  = ['key', 'openPalette', 'closePalette', 'filter', 'execute', 'demo'];

    private const COMMANDS = ['clear' => 'Clear the log', 'hello' => 'Say hello', 'close' => 'Close the palette'];

    /** @var array<string, bool> */
    private array $mods = ['Shift' => false, 'Ctrl' => false, 'Alt' => false, 'Meta' => false];

    /** @var list<string> */
    private array $log = [];

    private bool $open = false;

    private string $query = '';

    private string $said = '';

    public function key(KeyboardEventArgs $e): void
    {
        $this->mods  = ['Shift' => $e->shiftKey, 'Ctrl' => $e->ctrlKey, 'Alt' => $e->altKey, 'Meta' => $e->metaKey];
        $this->log[] = \sprintf('%-9s %-8s %s%s', $e->type, ' ' === $e->key ? 'Space' : $e->key, $e->code, $e->repeat ? ' (repeat)' : '');
        $this->log   = \array_slice($this->log, -8);
    }

    public function openPalette(): void
    {
        $this->open  = true;
        $this->query = '';
        $this->awaitRender();
        $this->browser()->ref('palette')->focus();
    }

    public function closePalette(): void
    {
        $this->open = false;
    }

    public function filter(string $query): void
    {
        $this->query = $query;
    }

    public function execute(string $command = ''): void
    {
        $command = '' === $command ? ($this->matches()[0] ?? '') : $command;
        match ($command) {
            'clear' => $this->log = [],
            'hello' => $this->said = 'Hello from the palette at ' . \date('H:i:s'),
            default => null,
        };
        $this->open = false;
    }

    /** @return list<string> */
    private function matches(): array
    {
        return \array_keys(\array_filter(self::COMMANDS, fn (string $label) => '' === $this->query || \str_contains(\strtolower($label), \strtolower($this->query))));
    }

    protected function demo(): string
    {
        $mods = '';
        foreach ($this->mods as $name => $down) {
            $mods .= '<kbd class="' . ($down ? 'on' : '') . "\">{$name}</kbd>";
        }
        $log     = $this->e(\implode("\n", $this->log));
        $palette = '';
        if ($this->open) {
            $items = '';
            foreach ($this->matches() as $command) {
                $items .= "<li><button type=\"button\" tether-click=\"execute\" tether-args='[\"{$command}\"]'>" . $this->e(self::COMMANDS[$command]) . '</button></li>';
            }
            $palette = <<<HTML
                <div class="palette" id="palette-box" role="dialog">
                  <input tether-ref="palette" id="palette" placeholder="Type a command" autocomplete="off" value="{$this->e($this->query)}" tether-input="filter" tether-keydown.key-enter="execute">
                  <ul>{$items}</ul>
                </div>
                HTML;
        }

        return <<<HTML
            <div tether-on-keydown.document.mod.key-k="openPalette" tether-on-keydown.document.key-escape="closePalette">
              <div class="keybox" id="keybox" tabindex="0" tether-on-keydown="key" tether-on-keypress="key" tether-on-keyup="key">
                <div class="mods">{$mods}</div>
                <pre id="keylog">{$log}</pre>
                <span class="hint">Click here and type</span>
              </div>
              <p class="row"><span id="said">{$this->said}</span></p>
              {$palette}
            </div>
            HTML;
    }
}
