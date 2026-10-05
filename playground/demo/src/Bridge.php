<?php

namespace Demo;

use Tether\Component;
use Tether\Invokable;
use Tether\JsException;
use Tether\JsStaleException;

/**
 * Every way the server calls the browser (a button each, the result in #out as JSON), and the
 * handlers the browser invokes. tests/browser/interop.mjs drives it.
 */
final class Bridge extends Component
{
    /** @var array<string, mixed> */
    private array $out = [];

    private bool $victim = true;

    private bool $life = true;

    private bool $slow = false;

    private int $renders = 0;

    private function note(string $name, mixed $value): void
    {
        $this->out[$name] = $value;
    }

    public function draw(): void
    {
        $ctx = $this->browser()->executeString('return document.getElementById("canvas").getContext("2d");');
        $ctx->fillStyle = 'rgb(200, 0, 0)';
        $ctx->fillRect(10, 10, 50, 50);
        $ctx->fillStyle = 'rgba(0, 0, 200, 0.5)';
        $ctx->fillRect(30, 30, 50, 50);
        $this->note('draw', true);
    }

    public function pair(): void
    {
        $browser = $this->browser();
        $start   = \microtime(true);
        $wait    = fn (int $n) => $browser->executeString('return new Promise((done) => setTimeout(() => done(n), 400));', ['n' => $n]);
        $first   = $wait(1);
        $second  = $wait(2);
        $this->note('pair', ['values' => [\phasync::await($first), \phasync::await($second)], 'ms' => (int) ((\microtime(true) - $start) * 1000)]);
    }

    public function typeError(): void
    {
        try {
            $this->browser()->call('nope.missing');
        } catch (JsException $e) {
            $this->note('typeError', [$e->jsName, $e->getMessage()]);
        }
    }

    public function rejected(): void
    {
        try {
            \phasync::await($this->browser()->executeString('return Promise.reject(new RangeError("too far"));'));
        } catch (JsException $e) {
            $this->note('rejected', [$e->jsName, $e->getMessage()]);
        }
    }

    public function module(): void
    {
        $module = $this->browser()->import('/interop-module.js');
        $this->note('module', [$module->greeting, $module->double(21)]);
    }

    public function helpers(): void
    {
        $browser = $this->browser();
        $field   = $browser->ref('field');
        $field->focus();
        $field->select();
        $box = $browser->ref('box');
        $box->scrollTo(0, 50);
        $browser->call('localStorage.setItem', 'tether', 'local');
        $browser->call('sessionStorage.setItem', 'tether', 'session');
        $browser->document->title = 'Set by the server';
        $browser->call('history.pushState', null, '', '#pushed');
        $this->note('helpers', [
            'focused'   => $browser->document->activeElement->id,
            'selected'  => $browser->executeString('return [field.selectionStart, field.selectionEnd];', ['field' => $field]),
            'scrollTop' => (int) $box->scrollTop,
            'local'     => $browser->call('localStorage.getItem', 'tether'),
            'session'   => $browser->call('sessionStorage.getItem', 'tether'),
            'title'     => $browser->document->title,
            'hash'      => $browser->window->location->hash,
            'width'     => $browser->window->innerWidth > 0,
        ]);
    }

    public function clipboard(): void
    {
        $browser = $this->browser();
        try {
            \phasync::await($browser->call('navigator.clipboard.writeText', 'copied by the server'));
            $this->note('clipboard', \phasync::await($browser->call('navigator.clipboard.readText')));
        } catch (JsException $e) {
            $this->note('clipboard', $e->jsName);
        }
    }

    public function stale(): void
    {
        $victim       = $this->browser()->ref('victim');
        $this->victim = false;
        $this->awaitRender();
        try {
            $victim->id;
            $this->note('stale', 'still there');
        } catch (JsStaleException) {
            $this->note('stale', 'stale');
        }
    }

    public function leak(): void
    {
        $browser = $this->browser();
        $dates   = [];
        for ($i = 0; $i < 20; ++$i) {
            $dates[] = $browser->new('Date', $i);
        }
        $during = $browser->call('Tether.handleCount');
        $dates  = null;
        $this->note('leak', $during);
    }

    public function timeout(): void
    {
        $browser = $this->browser();
        try {
            $browser->within(0.3, fn () => $browser->import('/interop-hang.js'));
        } catch (JsException $e) {
            $this->note('timeout', $e::class);
        }
    }

    public function forbidden(): void
    {
        $browser = $this->browser();
        $names   = [];
        foreach ([fn () => $browser->call('eval', '1'), fn () => $browser->window->constructor, fn () => $browser->call('Function', 'return 1')] as $try) {
            try {
                $try();
                $names[] = 'allowed';
            } catch (JsException $e) {
                $names[] = $e->jsName;
            }
        }
        $this->note('forbidden', $names);
    }

    public function toggleLife(): void
    {
        $this->life = !$this->life;
    }

    public function showSlow(): void
    {
        $this->slow = true;
        $this->awaitRender();
        $this->note('slow', $this->browser()->hook('Slow')->ready);
    }

    public function ping(): void
    {
    }

    #[Invokable]
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    #[Invokable]
    public function fail(): never
    {
        throw new \RuntimeException('the secret of the server');
    }

    #[Invokable]
    public function pause(): string
    {
        \phasync::sleep(1.0);

        return 'late';
    }

    public function hidden(): string
    {
        return 'not for the browser';
    }

    public function render(): string
    {
        ++$this->renders;
        $out    = \htmlspecialchars(\json_encode($this->out), \ENT_QUOTES);
        $victim = $this->victim ? '<span id="victim" tether-ref="victim">victim</span>' : '';
        $life   = $this->life ? "<p id=\"life\" tether-hook=\"Life\">renders: {$this->renders}</p>" : '';
        $slow   = $this->slow ? '<p id="slow" tether-hook="Slow">slow</p>' : '';
        $rows   = \implode('', \array_map(fn (string $b) => "<button id=\"b-{$b}\" tether-click=\"{$b}\">{$b}</button> ", [
            'draw', 'pair', 'typeError', 'rejected', 'module', 'helpers', 'clipboard', 'stale', 'leak', 'timeout', 'forbidden', 'toggleLife', 'showSlow', 'ping',
        ]));

        return <<<HTML
            <section>
              <canvas id="canvas" tether-ref="canvas" width="100" height="100"></canvas>
              <input id="field" tether-ref="field" value="select me">
              <div id="box" tether-ref="box" style="height: 40px; overflow: auto"><div style="height: 400px">tall</div></div>
              {$victim}{$life}{$slow}
              <p>{$rows}</p>
              <pre id="out">{$out}</pre>
            </section>
            HTML;
    }
}
