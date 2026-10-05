<?php

namespace Showcase;

use Tether\JsException;

final class Objects extends Section
{
    public const ID    = 'objects';
    public const TITLE = 'Browser objects in PHP';
    protected const BLURB = 'window->fetch() starts a request in the browser and returns a promise; phasync::await() waits for it, and the Response is read through the proxy. An element is a proxy too: its methods run in the browser, and value() copies a result back as data.';
    protected const SHOW  = ['fetch', 'measure', 'demo'];

    private string $fetched = '';

    private string $rect = '';

    public function fetch(): void
    {
        $browser = $this->browser();
        try {
            $response = \phasync::await($browser->window->fetch('/api/hello'), 5.0);
            $text     = \phasync::await($response->text(), 5.0);
            $this->fetched = "{$response->status} {$response->statusText}: {$text}";
        } catch (JsException $e) {
            $this->fetched = "{$e->jsName}: {$e->getMessage()}";
        }
    }

    public function measure(): void
    {
        $element = $this->browser()->document->querySelector('#measured');
        $element->scrollIntoView(['block' => 'center']);
        $rect       = $element->getBoundingClientRect()->value();
        $this->rect = \sprintf('%d x %d at %d, %d', $rect['width'], $rect['height'], $rect['x'], $rect['y']);
    }

    protected function demo(): string
    {
        return <<<HTML
            <p class="row"><button id="fetch" tether-click="fetch">Fetch /api/hello from the server</button></p>
            <pre class="out" id="fetched">{$this->e($this->fetched)}</pre>
            <p class="row"><button id="measure" tether-click="measure">Scroll to the box and measure it</button></p>
            <div class="measured" id="measured">A box the server finds with querySelector()</div>
            <pre class="out" id="rect">{$this->e($this->rect)}</pre>
            HTML;
    }
}
