<?php

namespace Showcase;

use Tether\Invokable;
use Tether\JsException;

final class Interop extends Section
{
    public const ID    = 'interop';
    public const TITLE = 'Clipboard, storage and element references';
    protected const BLURB = 'The server calls browser functions by name and waits for the answer. A tether-ref names an element for the server to focus or scroll to. A plain script calls a server method with Tether.invoke() and gets the result.';
    protected const SHOW  = ['run', 'copy', 'setAccent', 'paint', 'focusName', 'scrollToEnd', 'square', 'demo'];
    protected const JS    = ['invoke'];

    private const ACCENTS = ['#4f6df5', '#e0457b', '#12a594', '#d98e04'];

    private string $token = '';

    private string $copied = '';

    private string $accent = '';

    public function mount(): void
    {
        $this->token = \strtoupper(\bin2hex(\random_bytes(4)));
    }

    public function run(): void
    {
        $saved = $this->browser()->call('localStorage.getItem', 'showcase.accent');
        if (null !== $saved) {
            $this->paint($saved);
            $this->requestRender();
        }
    }

    public function copy(): void
    {
        try {
            \phasync::await($this->browser()->call('navigator.clipboard.writeText', $this->token), 5.0);
            $this->copied = "Copied {$this->token}";
        } catch (JsException $e) {
            $this->copied = "The browser refused: {$e->jsName}";
        }
    }

    public function setAccent(string $colour): void
    {
        $this->browser()->call('localStorage.setItem', 'showcase.accent', $colour);
        $this->paint($colour);
    }

    public function focusName(): void
    {
        $this->browser()->ref('name')->focus();
    }

    public function scrollToEnd(): void
    {
        $this->browser()->ref('end')->scrollIntoView(['behavior' => 'smooth', 'block' => 'center']);
    }

    /** @return array{n: int, square: int, worker: int} */
    #[Invokable]
    public function square(int $n): array
    {
        return ['n' => $n, 'square' => $n * $n, 'worker' => \getmypid()];
    }

    private function paint(string $colour): void
    {
        $this->accent = $colour;
        $this->browser()->call('document.documentElement.style.setProperty', '--accent', $colour);
    }

    protected function demo(): string
    {
        $swatches = '';
        foreach (self::ACCENTS as $colour) {
            $swatches .= "<button type=\"button\" class=\"swatch" . ($this->accent === $colour ? ' on' : '') . "\" style=\"background: {$colour}\" aria-label=\"{$colour}\" tether-click=\"setAccent\" tether-args='[\"{$colour}\"]'></button>";
        }

        return <<<HTML
            <div class="grid2">
              <div>
                <h3>Clipboard</h3>
                <p class="row"><code id="token">{$this->token}</code> <button id="copy" tether-click="copy">Copy</button></p>
                <p class="muted" id="copied">{$this->copied}</p>
              </div>
              <div>
                <h3>Saved in localStorage</h3>
                <p class="row" id="swatches">{$swatches}</p>
                <p class="muted">Reload the page: the accent colour stays.</p>
              </div>
              <div>
                <h3>Element references</h3>
                <p class="row"><input tether-ref="name" id="name" placeholder="A name"> <button id="focus-name" tether-click="focusName">Focus it</button></p>
                <p class="row"><button id="scroll-end" tether-click="scrollToEnd">Scroll to the end of this card</button></p>
              </div>
              <div>
                <h3>Tether.invoke() from a script</h3>
                <p class="row"><button id="invoke" data-invoke="7">square(7)</button> <output id="invoke-result" tether-ignore></output></p>
              </div>
            </div>
            <p class="muted" tether-ref="end" id="interop-end">End of the card.</p>
            HTML;
    }
}
