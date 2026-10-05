<?php

namespace Plain;

use Tether\Component;
use Tether\JsObject;

/** Two pages that link to each other with tether-boost: a click on a link morphs the next page in, without a reload. */
final class Boost extends Component
{
    public string $page = 'a';
    public int $count = 0;
    private ?JsObject $held = null;

    public function increment(): void
    {
        ++$this->count;
    }

    public function hold(): void
    {
        $this->held = $this->browser()->new('Date');
    }

    public function render(): string
    {
        $other = 'a' === $this->page ? 'b' : 'a';

        return <<<HTML
            <main>
              <nav tether-boost>
                <a id="next" href="/boost/{$other}">Page {$other}</a>
                <a id="blank" href="/boost/{$other}" target="_blank">New tab</a>
                <a id="plain" href="/plain.html">Not Tether</a>
                <a id="hash" href="/boost/{$this->page}#end">Down</a>
                <span tether-boost="off"><a id="off" href="/boost/{$other}">Off</a></span>
              </nav>
              <a id="unmarked" href="/boost/{$other}?unmarked">Unmarked</a>
              <h1>Page {$this->page}</h1>
              <p>Clicked <b id="count">{$this->count}</b> times
                <button id="inc" tether-click="increment">+1</button>
                <button id="hold" tether-click="hold">Hold</button></p>
              <div style="height: 2000px"></div>
              <p id="end">End</p>
            </main>
            HTML;
    }
}
