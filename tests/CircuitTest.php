<?php

use Tether\Circuit;
use Tether\Component;

/** Counts its run() ticks in $ticks[its label], and shows a child when $showChild. */
final class Ticker extends Component
{
    public static array $ticks = [];

    public string $label = '';

    public bool $showChild = false;

    public function run(): void
    {
        while (true) {
            self::$ticks[$this->label] = (self::$ticks[$this->label] ?? 0) + 1;
            phasync::sleep(0.01);
        }
    }

    public function hide(): void
    {
        $this->showChild = false;
    }

    public function render(): string
    {
        return '<div>' . ($this->showChild ? $this->child(self::class, ['label' => "{$this->label}/child", 'showChild' => substr_count($this->label, '/') < 1]) : '') . '</div>';
    }
}

/** Three levels of tickers; runs $act on the circuit and reports which kept ticking after it. */
function ticking_after(Closure $act): array
{
    return phasync::run(function () use ($act) {
        Ticker::$ticks = [];
        $circuit       = new Circuit(static function (array $frame) {});
        // root shows a child, which shows a grandchild
        $circuit->mount(Ticker::class, ['label' => 'root', 'showChild' => true]);
        phasync::sleep(0.05);
        $act($circuit);
        $before = Ticker::$ticks;
        phasync::sleep(0.05);
        $circuit->close();
        $ticking = [];
        foreach ($before as $label => $n) {
            $ticking[$label] = Ticker::$ticks[$label] > $n;
        }
        ksort($ticking);

        return $ticking;
    });
}

test('every component runs its run() coroutine while mounted', function () {
    expect(ticking_after(static function () {}))->toBe(['root' => true, 'root/child' => true, 'root/child/child' => true]);
});

test('a child its parent no longer renders is unmounted, with its own children, and their coroutines are cancelled', function () {
    expect(ticking_after(static function (Circuit $circuit) {
        $circuit->event('c1', 'hide', []);
        phasync::sleep(0.02); // the event is handled, and the root renders
    }))->toBe(['root' => true, 'root/child' => false, 'root/child/child' => false]);
});

test('closing the circuit (the tab is gone) cancels every coroutine', function () {
    expect(ticking_after(static fn (Circuit $circuit) => $circuit->close()))->toBe(['root' => false, 'root/child' => false, 'root/child/child' => false]);
});

test('updates in the same turn render once; the patch lists the components rendered', function () {
    $frames = phasync::run(function () {
        $frames  = [];
        $circuit = new Circuit(static function (array $frame) use (&$frames) { $frames[] = $frame; });
        $circuit->mount(Ticker::class, ['label' => 'r', 'showChild' => true]);
        $circuit->event('c1', 'hide', []);
        $circuit->event('c1', 'hide', []);
        phasync::sleep(0.05);
        $circuit->close();

        return $frames;
    });
    $patches = array_merge(...array_map(static fn ($f) => $f['patches'], $frames));
    $c1      = array_values(array_filter($patches, static fn ($p) => 'c1' === $p['id']));
    expect(count($c1))->toBeLessThanOrEqual(2);
    expect($c1[0]['html'])->toBe('<div tether-id="c1"></div>');
});
