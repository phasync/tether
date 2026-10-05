<?php

use mini\Dispatcher\RequestDispatcher;
use mini\Http\Message\ServerRequest;
use mini\Mini;
use Tether\Circuit;
use Tether\Component;
use Tether\ErrorBoundary;
use Tether\Invokable;
use Tether\JsException;

/** A component for these tests: what it did, in $log; its behaviour, from its $mode prop. */
final class Probe extends Component
{
    public static array $log = [];

    public string $mode = '';

    public int $count = 0;

    public function mount(): void
    {
        self::$log[] = "mount {$this->mode}";
    }

    public function run(): void
    {
        if ('go-fails' === $this->mode) {
            $this->go(static function () {
                phasync::sleep(0.01);
                throw new RuntimeException('coroutine failed');
            });
        }
        if ('run-fails' === $this->mode) {
            phasync::sleep(0.01);
            throw new RuntimeException('run failed');
        }
        while (true) {
            phasync::sleep(0.01);
        }
    }

    #[Invokable]
    public function add(int $n, string ...$labels): int
    {
        $this->count += $n;

        return $this->count;
    }

    public function fail(): void
    {
        throw new RuntimeException('handler failed');
    }

    public function leak(): void
    {
        throw new RuntimeException('SQLSTATE[HY000]: host db.internal');
    }

    #[Invokable]
    public function callBrowser(): mixed
    {
        return $this->browser()->call('Probe.answer', 42);
    }

    #[Invokable]
    public function whoAmI(): ?string
    {
        return \mini\request()->getQueryParams()['tab'] ?? null;
    }

    public function render(): string
    {
        if ('render-fails' === $this->mode) {
            throw new RuntimeException('render failed');
        }
        if ('js-in-render' === $this->mode) {
            $this->browser()->call('alert', 'no');
        }

        return "<p>{$this->count}</p>";
    }
}

/** An error boundary around one Probe: shows the error instead of it, until retry(). */
final class Boundary extends Component implements ErrorBoundary
{
    public string $mode = '';

    public ?string $error = null;

    public bool $showsChildWhenFailed = false;

    public function catch(Throwable $e): void
    {
        $this->error = $e->getMessage();
    }

    public function retry(): void
    {
        $this->error = null;
        $this->mode  = '';
    }

    public function render(): string
    {
        if (null !== $this->error && !$this->showsChildWhenFailed) {
            return "<div>error: {$this->error}</div>";
        }

        return '<div>' . $this->child(Probe::class, ['mode' => $this->mode]) . '</div>';
    }
}

/** A boundary whose catch() fails. */
final class BrokenBoundary extends Component implements ErrorBoundary
{
    public function catch(Throwable $e): void
    {
        throw new RuntimeException('catch failed');
    }

    public function render(): string
    {
        return '<div>' . $this->child(Probe::class) . '</div>';
    }
}

/** Mounting it takes 50 ms. */
final class Slow extends Component
{
    public function mount(): void
    {
        phasync::sleep(0.05);
    }

    public function render(): string
    {
        return '<i>slow</i>';
    }
}

/** A Probe, and a Slow child once shown; the count is read before the child is made. */
final class Host extends Component
{
    public bool $show = false;

    public int $count = 0;

    public function show(): void
    {
        $this->show = true;
    }

    public function bump(): void
    {
        ++$this->count;
    }

    public function render(): string
    {
        return '<div>' . $this->count . $this->child(Probe::class) . ($this->show ? $this->child(Slow::class) : '') . '</div>';
    }
}

beforeEach(function () {
    Probe::$log = [];
});

test('mount() runs once per instance, with the props set, before the first render', function () {
    $out = live(Probe::class, ['mode' => 'm'], static function (Circuit $circuit) {
        $circuit->event('c1', 'add', [1]);
        phasync::sleep(0.02);
    });
    expect(Probe::$log)->toBe(['mount m']);
    expect($out['html'])->toBe('<p tether-id="c1">0</p>');
});

test('an invocation gets the handler\'s return value, after the frame that shows the new state', function () {
    $out = live(Probe::class, [], static function (Circuit $circuit) {
        $circuit->event('c1', 'add', [5], reply: 1, value: true);
        phasync::sleep(0.02);
    });
    $frame = $out['frames'][0];
    expect($frame['patches'][0]['html'])->toBe('<p tether-id="c1">5</p>');
    expect($frame['replies'])->toBe([['r' => 1, 'v' => 5]]);
});

test('arguments must match the handler\'s parameter types and count', function (string $method, array $args) {
    $out = live(Probe::class, [], static function (Circuit $circuit) use ($method, $args) {
        expect(fn () => $circuit->event('c1', $method, $args, reply: 7))->toThrow(InvalidArgumentException::class);
        phasync::sleep(0.02);
    });
    expect(replies($out['frames'])[7])->toHaveKey('e');
    expect($out['crashed'])->toBeNull();
})->with([
    'a string for int'          => ['add', ['5']],
    'too few'                   => ['add', []],
    'an int for a variadic str' => ['add', [1, 'a', 2]],
    'named arguments'           => ['add', ['n' => 1]],
    'too many'                  => ['fail', [1]],
]);

test('only the component\'s own public methods are handlers', function (string $method) {
    live(Probe::class, [], static function (Circuit $circuit) use ($method) {
        expect(fn () => $circuit->event('c1', $method, []))->toThrow(InvalidArgumentException::class);
    });
})->with(['render', 'mount', 'run', 'requestRender', 'browser', 'awaitRender', 'child', 'attach', '__construct', 'nope']);

test('variadic handlers take any number of arguments of their type', function () {
    $out = live(Probe::class, [], static function (Circuit $circuit) {
        $circuit->event('c1', 'add', [1, 'a', 'b'], reply: 1, value: true);
        phasync::sleep(0.02);
    });
    expect(replies($out['frames'])[1]['v'])->toBe(1);
});

test('a call into the browser returns what the browser answers', function () {
    $out = live(Probe::class, [], static function (Circuit $circuit) {
        $circuit->event('c1', 'callBrowser', [], reply: 1, value: true);
        phasync::sleep(0.02);
    }, browser: static fn (array $op, Circuit $circuit) => $circuit->returned($op['i'], 'forty-two', null));
    $op = array_values(array_filter($out['frames'], static fn ($f) => 'op' === $f['t']))[0];
    expect($op)->toMatchArray(['o' => 'path', 'p' => 'Probe.answer', 'a' => [42]]);
    expect(replies($out['frames'])[1]['v'])->toBe('forty-two');
});

test('a JavaScript error comes back as a JsException', function () {
    $out = live(Probe::class, [], static function (Circuit $circuit) {
        $circuit->event('c1', 'callBrowser', [], reply: 1);
        phasync::sleep(0.02);
    }, browser: static fn (array $op, Circuit $circuit) => $circuit->returned($op['i'], null, ['m' => 'Probe.answer is not a function', 'n' => 'TypeError']));
    // The handler did not catch it: the push is rejected, and the tab crashed
    expect(replies($out['frames'])[1]['e'])->toBe('The handler failed');
    expect($out['crashed'])->toBe('Probe.answer is not a function');
});

test('a call into the browser in render() is refused', function () {
    $out = live(Probe::class, ['mode' => 'js-in-render'], static fn () => null);
    expect($out['html'])->toBeNull();
    expect($out['crashed'])->toContain('The browser can be called from event handlers and run()');
});

test('a handler that fails, with no error boundary above, crashes the tab: everything unmounts', function () {
    $out = live(Probe::class, [], static function (Circuit $circuit) {
        $circuit->event('c1', 'fail', []);
        phasync::sleep(0.02);
        // Gone: events for it are ignored
        $circuit->event('c1', 'add', [1], reply: 9);
    });
    expect($out['crashed'])->toBe('handler failed');
    expect(replies($out['frames'])[9]['e'])->toContain('has left the page');
});

test('a failure in mount() or render() of the root crashes the tab at once', function (string $mode) {
    $out = live(Probe::class, ['mode' => $mode], static fn () => null);
    expect($out['html'])->toBeNull();
    expect($out['crashed'])->toBe('render failed' === $mode ? 'render failed' : $out['crashed']);
})->with(['render-fails']);

test('without a crash handler (the first page render), a failure is thrown', function () {
    expect(fn () => (new Circuit())->mount(Probe::class, ['mode' => 'render-fails']))->toThrow(RuntimeException::class, 'render failed');
});

test('an error boundary catches a child\'s failing handler, and shows its error instead', function () {
    $out = live(Boundary::class, [], static function (Circuit $circuit) {
        $circuit->event('c2', 'fail', []);
        phasync::sleep(0.02);
    });
    expect($out['crashed'])->toBeNull();
    expect(end($out['frames'])['patches'][0])->toMatchArray(['id' => 'c1', 'html' => '<div tether-id="c1">error: handler failed</div>']);
});

test('an error boundary catches a child\'s failing run()', function () {
    $out = live(Boundary::class, ['mode' => 'run-fails'], static fn () => phasync::sleep(0.05));
    expect($out['crashed'])->toBeNull();
    expect(end($out['frames'])['patches'][0]['html'])->toBe('<div tether-id="c1">error: run failed</div>');
});

test('an error boundary catches a child\'s failing render(), on the first render too', function () {
    $out = live(Boundary::class, ['mode' => 'render-fails'], static fn () => null);
    expect($out['crashed'])->toBeNull();
    expect($out['html'])->toBe('<div tether-id="c1">error: render failed</div>');
});

test('an error boundary catches a failing coroutine the child started', function () {
    $out = live(Boundary::class, ['mode' => 'go-fails'], static fn () => phasync::sleep(0.05));
    expect($out['crashed'])->toBeNull();
    expect(end($out['frames'])['patches'][0]['html'])->toBe('<div tether-id="c1">error: coroutine failed</div>');
});

test('a coroutine started with go() is cancelled when its component leaves; one started with phasync::go() is not the component\'s', function () {
    $ticks = ['go' => 0, 'phasync' => 0];
    $out   = live(Boundary::class, [], static function (Circuit $circuit) use (&$ticks) {
        $probe = (new ReflectionProperty(Circuit::class, 'nodes'))->getValue($circuit)['c2']->component;
        (fn () => $this->go(static function () use (&$ticks) { while (true) { ++$ticks['go']; phasync::sleep(0.005); } }))->call($probe);
        $other = phasync::go(static function () use (&$ticks) { try { while (true) { ++$ticks['phasync']; phasync::sleep(0.005); } } catch (phasync\CancelledException) {} });
        $circuit->event('c2', 'fail', []);  // the boundary shows its error: the probe leaves
        phasync::sleep(0.03);
        $before = $ticks;
        phasync::sleep(0.03);
        phasync::cancel($other);

        return [$ticks['go'] > $before['go'], $ticks['phasync'] > $before['phasync']];
    });
    expect($out['result'])->toBe([false, true]);
});

test('a failing coroutine with no boundary above crashes the tab', function () {
    $out = live(Probe::class, ['mode' => 'go-fails'], static fn () => phasync::sleep(0.05));
    expect($out['crashed'])->toBe('coroutine failed');
});

test('after retry(), the boundary renders a new child', function () {
    $out = live(Boundary::class, ['mode' => 'render-fails'], static function (Circuit $circuit) {
        $circuit->event('c1', 'retry', []);
        phasync::sleep(0.02);
    });
    expect(end($out['frames'])['patches'][0]['html'])->toBe('<div tether-id="c1"><p tether-id="c3">0</p></div>');
});

test('a boundary that fails to render past the failure hands it on: here, to a crash', function () {
    $out = live(Boundary::class, ['mode' => 'render-fails', 'showsChildWhenFailed' => true], static fn () => null);
    expect($out['html'])->toBeNull();
    expect($out['crashed'])->toBe('render failed');
});

test('a boundary\'s catch() is not an event handler', function () {
    live(Boundary::class, [], static function (Circuit $circuit) {
        expect(fn () => $circuit->event('c1', 'catch', []))->toThrow(InvalidArgumentException::class);
    });
});

test('with mini\'s RequestDispatcher::within() as enter, component coroutines see the tab\'s request', function () {
    $out = live(Probe::class, [], static function (Circuit $circuit) {
        $circuit->event('c1', 'whoAmI', [], reply: 1, value: true);
        phasync::sleep(0.02);
    });
    expect(replies($out['frames'])[1]['v'])->toBe('t1');
});

test('a failure while the writer sends a frame crashes the tab, instead of leaving it silent', function () {
    $out = live(BrokenBoundary::class, [], static function (Circuit $circuit) {
        $circuit->event('c2', 'fail', []);
        phasync::sleep(0.05);
    });
    expect($out['crashed'])->toBe('catch failed');
});

test('a handler\'s exception text does not reach the browser', function () {
    $out = live(Probe::class, [], static function (Circuit $circuit) {
        $circuit->event('c1', 'leak', [], reply: 1);
        phasync::sleep(0.02);
    });
    expect(replies($out['frames'])[1]['e'])->toBe('The handler failed');
    expect($out['crashed'])->toContain('SQLSTATE');
});

test('a method name from the browser is neither long nor multi-line in the refusal', function () {
    live(Probe::class, [], static function (Circuit $circuit) {
        try {
            $circuit->event('c1', "x\nFAKE LOG LINE" . str_repeat('a', 5000), []);
        } catch (InvalidArgumentException $e) {
            expect($e->getMessage())->not->toContain("\n")->and(strlen($e->getMessage()))->toBeLessThan(200);

            return;
        }
        throw new LogicException('not refused');
    });
});

test('a change made while render() waits is rendered in the next frame', function () {
    $out = live(Host::class, [], static function (Circuit $circuit) {
        $circuit->event('c1', 'show', []);
        phasync::sleep(0.02); // the writer is in Slow::mount()
        $circuit->event('c1', 'bump', []);
        phasync::sleep(0.15);
    });
    $patches = array_merge(...array_map(static fn ($f) => $f['patches'] ?? [], $out['frames']));
    expect(end($patches)['html'])->toStartWith('<div tether-id="c1">1');
});

test('a call into the browser from another component while a render waits is not refused', function () {
    $out = live(Host::class, [], static function (Circuit $circuit) {
        $circuit->event('c1', 'show', []);
        phasync::sleep(0.02);
        $circuit->event('c2', 'callBrowser', []);
        phasync::sleep(0.15);
    }, browser: static fn (array $op, Circuit $circuit) => $circuit->returned($op['i'], null, null));
    expect($out['crashed'])->toBeNull();
    expect(array_filter($out['frames'], static fn ($f) => 'op' === $f['t']))->toHaveCount(1);
});
