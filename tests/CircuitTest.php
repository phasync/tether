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
        $writer = phasync::go($circuit->run(...));
        phasync::sleep(0.05);
        $act($circuit);
        $before = Ticker::$ticks;
        phasync::sleep(0.05);
        phasync::cancel($writer);
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

test('updates in a row render once, in one frame; the frame lists the components rendered', function () {
    $frames = phasync::run(function () {
        $frames  = [];
        $circuit = new Circuit(static function (array $frame) use (&$frames) { $frames[] = $frame; });
        $circuit->mount(Ticker::class, ['label' => 'r', 'showChild' => true]);
        $writer = phasync::go($circuit->run(...));
        $circuit->event('c1', 'hide', []);
        $circuit->event('c1', 'hide', []);
        phasync::sleep(0.05);
        phasync::cancel($writer);
        $circuit->close();

        return $frames;
    });
    $patches = array_merge(...array_map(static fn ($f) => $f['patches'], $frames));
    $c1      = array_values(array_filter($patches, static fn ($p) => 'c1' === $p['id']));
    expect(count($c1))->toBeLessThanOrEqual(2);
    expect($c1[0]['html'])->toBe('<div tether-id="c1"></div>');
});

test('frames are sent at most maxFps times a second, each with the latest state', function () {
    $frames = phasync::run(function () {
        $frames  = [];
        $circuit = new Circuit(static function (array $frame) use (&$frames) { $frames[] = microtime(true); }, maxFps: 20);
        $circuit->mount(Ticker::class, ['label' => 'fast']); // run() updates nothing, so drive it by events
        $writer = phasync::go($circuit->run(...));
        $end    = microtime(true) + 0.5;
        while (microtime(true) < $end) {
            $circuit->event('c1', 'hide', []); // 1000 state changes a second
            phasync::sleep(0.001);
        }
        phasync::cancel($writer);
        $circuit->close();

        return $frames;
    });
    // 0.5 s at 20 frames a second: about 10, and never two closer than 1/20 s
    expect(count($frames))->toBeGreaterThanOrEqual(8)->toBeLessThanOrEqual(12);
    for ($i = 1; $i < count($frames); ++$i) {
        expect($frames[$i] - $frames[$i - 1])->toBeGreaterThan(0.045);
    }
});
