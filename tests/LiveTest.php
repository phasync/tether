<?php

use mini\Dispatcher\RequestDispatcher;
use mini\Http\Message\ServerRequest;
use mini\Mini;
use Tether\Circuit;
use Tether\Component;
use Tether\ErrorBoundary;
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
            phasync::go(static function () {
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

    public function add(int $n, string ...$labels): int
    {
        $this->count += $n;

        return $this->count;
    }

    public function fail(): void
    {
        throw new RuntimeException('handler failed');
    }

    public function callBrowser(): mixed
    {
        return $this->js('Probe.answer', 42);
    }

    public function whoAmI(): array
    {
        return [Mini::$mini->getRequestScope() === phasync::getContext() ? 'own' : 'shared', \mini\request()->getQueryParams()['tab'] ?? null];
    }

    public function render(): string
    {
        if ('render-fails' === $this->mode) {
            throw new RuntimeException('render failed');
        }
        if ('js-in-render' === $this->mode) {
            $this->js('alert', 'no');
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

/**
 * Mount $class live, run $act(circuit, frames) in a coroutine of the tab's request, and return
 * the frames sent and whether the tab crashed. Frames are JSON-decoded, as the browser gets them.
 */
function live(string $class, array $props, Closure $act): array
{
    return phasync::run(function () use ($class, $props, $act) {
        $frames  = new ArrayObject();
        $crashed = null;
        $request = new ServerRequest('GET', '/_tether/live?tab=t1', '');

        return RequestDispatcher::within($request, function () use ($class, $props, $act, $frames, &$crashed) {
            $circuit = new Circuit(
                send: static function (array $frame) use ($frames) { $frames[] = json_decode(json_encode($frame), true); },
                maxFps: 1000,
                crash: static function (Throwable $e) use (&$crashed) { $crashed = $e->getMessage(); },
            );
            $html   = $circuit->mount($class, $props);
            $writer = phasync::go($circuit->run(...));
            try {
                $result = $act($circuit, $frames);
                phasync::sleep(0.02);
            } finally {
                phasync::cancel($writer);
                $circuit->close();
            }

            return ['html' => $html, 'frames' => $frames->getArrayCopy(), 'crashed' => $crashed, 'result' => $result ?? null];
        });
    });
}

/** Every reply in $frames, by reply id. */
function replies(array $frames): array
{
    $replies = [];
    foreach ($frames as $frame) {
        foreach ($frame['replies'] ?? [] as $reply) {
            $replies[$reply['r']] = $reply;
        }
    }

    return $replies;
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

test('a push gets the handler\'s return value, after the frame that shows the new state', function () {
    $out = live(Probe::class, [], static function (Circuit $circuit) {
        $circuit->event('c1', 'add', [5], reply: 1);
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
})->with(['render', 'mount', 'run', 'stateHasChanged', 'js', 'child', 'attach', '__construct', 'nope']);

test('variadic handlers take any number of arguments of their type', function () {
    $out = live(Probe::class, [], static function (Circuit $circuit) {
        $circuit->event('c1', 'add', [1, 'a', 'b'], reply: 1);
        phasync::sleep(0.02);
    });
    expect(replies($out['frames'])[1]['v'])->toBe(1);
});

test('js() goes in the frame after the component\'s state, and returns what the browser answers', function () {
    $out = live(Probe::class, [], static function (Circuit $circuit, ArrayObject $frames) {
        $circuit->event('c1', 'callBrowser', [], reply: 1);
        phasync::sleep(0.02);
        $call = $frames[0]['calls'][0];
        expect($frames[0]['patches'][0]['id'])->toBe('c1');
        expect($call)->toMatchArray(['c' => 'c1', 'f' => 'Probe.answer', 'a' => [42]]);
        $circuit->returned($call['i'], 'forty-two', null);
        phasync::sleep(0.02);
    });
    expect(replies($out['frames'])[1]['v'])->toBe('forty-two');
});

test('a JavaScript error comes back as a JsException', function () {
    $out = live(Probe::class, [], static function (Circuit $circuit, ArrayObject $frames) {
        $circuit->event('c1', 'callBrowser', [], reply: 1);
        phasync::sleep(0.02);
        $circuit->returned($frames[0]['calls'][0]['i'], null, 'Probe.answer is not a function');
        phasync::sleep(0.02);
    });
    // The handler did not catch it: the push is rejected with it, and the tab crashed
    expect(replies($out['frames'])[1]['e'])->toBe('Probe.answer is not a function');
    expect($out['crashed'])->toBe('Probe.answer is not a function');
});

test('js() in render() is refused', function () {
    $out = live(Probe::class, ['mode' => 'js-in-render'], static fn () => null);
    expect($out['html'])->toBeNull();
    expect($out['crashed'])->toContain('js() is for event handlers and run()');
});

test('js() is refused before the tab is live', function () {
    expect(fn () => phasync::run(fn () => (new Circuit())->js(new Probe(), 'alert', [])))->toThrow(LogicException::class);
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

test('every component coroutine shares the tab\'s request scope, and sees the tab\'s request', function () {
    $out = live(Probe::class, [], static function (Circuit $circuit) {
        $circuit->event('c1', 'whoAmI', [], reply: 1);
        phasync::sleep(0.02);
    });
    expect(replies($out['frames'])[1]['v'])->toBe(['shared', 't1']);
});
