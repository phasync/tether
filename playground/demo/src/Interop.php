<?php

namespace Demo;

use Tether\Component;
use Tether\JsException;

/**
 * Talks with its hook in the browser (html/demo.js): the hook pushes hello(), the server
 * calls the hook's elapsed(). The stopwatch is the browser's: tether-ignore keeps renders off it.
 */
final class Interop extends Component
{
    public string $greeting = '';

    public ?int $elapsed = null;

    public ?string $jsError = null;

    private int $renders = 0;

    public function hello(string $userAgent): string
    {
        $this->greeting = 'The server got your hello (' . (str_contains($userAgent, 'Chrome') ? 'Chrome' : 'a browser') . ')';

        return 'hello from the server';
    }

    public function measure(): void
    {
        $this->elapsed = $this->js('Stopwatch.elapsed');
    }

    public function badResult(): void
    {
        try {
            $this->js('Stopwatch.circular');
        } catch (JsException $e) {
            $this->jsError = $e->getMessage();
        }
    }

    public function render(): string
    {
        ++$this->renders;
        $measured = null === $this->elapsed ? '' : "<p id=\"measured\">The browser says {$this->elapsed} ms</p>";
        $jsError = null === $this->jsError ? '' : '<p id="js-error">' . htmlspecialchars($this->jsError) . '</p>';

        return <<<HTML
            <section tether-hook="Stopwatch">
              <p id="stopwatch" tether-ignore data-renders="{$this->renders}">Waiting to go live</p>
              <p id="greeting">{$this->greeting}</p>
              {$measured}
              {$jsError}
              <button tether-click="measure">Ask the browser</button>
              <button tether-click="badResult">Ask for something that is not JSON</button>
            </section>
            HTML;
    }
}
