<?php

use Tether\Browser;
use Tether\Circuit;
use Tether\Component;
use Tether\Invokable;
use Tether\JsException;
use Tether\JsLimitException;
use Tether\JsObject;
use Tether\JsStaleException;
use Tether\JsTimeoutException;
use Tether\Limits;

/** A component that runs the closure it is given, with its Browser, in an event handler, run() or mount(). */
final class Scripted extends Component
{
    public ?Closure $script = null;

    public ?Closure $onRun = null;

    public ?Closure $onMount = null;

    public int $count = 0;

    public function mount(): void
    {
        null === $this->onMount || ($this->onMount)($this);
    }

    public function run(): void
    {
        null === $this->onRun || ($this->onRun)($this->browser());
        phasync::sleep(60);
    }

    #[Invokable]
    public function play(): mixed
    {
        return ($this->script)($this->browser(), $this);
    }

    #[Invokable]
    public function bump(): mixed
    {
        ++$this->count;
        $this->awaitRender();

        return ($this->script)($this->browser(), $this);
    }

    #[Invokable]
    public function bumpAndCall(): mixed
    {
        ++$this->count;

        return ($this->script)($this->browser(), $this);
    }

    public function notInvokable(): void
    {
    }

    public function render(): string
    {
        return "<div>{$this->count}</div>";
    }
}

/** Shows a Scripted child when $on. */
final class Shower extends Component
{
    public bool $on = false;

    public array $childProps = [];

    public function show(): void
    {
        $this->on = true;
    }

    public function hide(): void
    {
        $this->on = false;
    }

    public function render(): string
    {
        return '<section>' . ($this->on ? $this->child(Scripted::class, $this->childProps) : '') . '</section>';
    }
}

/** Plays $script in c1 until the handler's reply arrives (or $wait s), and returns what the tab sent. */
function play(array $props, ?Closure $browser, ?Closure $after = null, array $options = [], string $event = 'play', float $wait = 2.0): array
{
    return live(Scripted::class, $props, static function (Circuit $circuit, ArrayObject $frames) use ($after, $event, $wait) {
        $circuit->event('c1', $event, [], reply: 1, value: true);
        for ($until = microtime(true) + $wait; microtime(true) < $until && !isset(replies($frames->getArrayCopy())[1]);) {
            phasync::sleep(0.005);
        }
        null === $after || $after($circuit, $frames);
    }, $options, $browser);
}

function ops(array $frames): array
{
    return array_values(array_filter($frames, static fn ($f) => 'op' === $f['t']));
}

/** An answer to every op: $value, unless it is a closure, which gets the op. */
function answers(mixed $value = null): Closure
{
    return static fn (array $op, Circuit $circuit) => $circuit->returned($op['i'], $value instanceof Closure ? $value($op) : $value, null);
}

function handle(int $id, string $kind = 'o'): array
{
    return ['$' => 'h', 'id' => $id, 'k' => $kind];
}

test('a call gives the browser\'s result, JSON by value', function () {
    $out = play(['script' => static fn (Browser $b) => $b->call('jsFunc', 1, ['a' => [2, null]], 'x')], answers(['n' => [1, 2], 's' => 'ok']));
    expect(ops($out['frames']))->toBe([['t' => 'op', 'i' => 1, 'o' => 'path', 'p' => 'jsFunc', 'a' => [1, ['a' => [2, null]], 'x']]]);
    expect(replies($out['frames'])[1]['v'])->toBe(['n' => [1, 2], 's' => 'ok']);
});

test('what JSON can not carry is a JsObject, which can be given back as an argument', function () {
    $out = play(['script' => static function (Browser $b) {
        $el = $b->call('document.getElementById', 'canvas');
        $b->call('use', $el, ['inside' => $el]);

        return $el instanceof JsObject;
    }], answers(fn ($op) => 'path' === $op['o'] && 'use' === $op['p'] ? 1 : handle(5)));
    expect(replies($out['frames'])[1]['v'])->toBeTrue();
    expect(ops($out['frames'])[1]['a'])->toBe([['$' => 'h', 'id' => 5], ['inside' => ['$' => 'h', 'id' => 5]]]);
});

test('a JsObject reads, writes, calls and more', function () {
    $out = play(['script' => static function (Browser $b) {
        $o   = $b->call('get');
        $r   = [];
        $r[] = $o->width;
        $o->width = 300;
        $r[] = isset($o->width);
        unset($o->width);
        $r[] = $o->fillRect(10, 10, 50, 50);
        $r[] = $o->call('call', 1);
        $r[] = $o(7);
        $r[] = $o['k'];
        $o['k'] = 'v';
        $r[] = isset($o['k']);
        unset($o['k']);
        $r[] = count($o);
        $r[] = (string) $o;
        $r[] = $o->value();

        return $r;
    }], answers(static fn ($op) => match ($op['o']) {
        'path' => handle(5),
        'has'  => true,
        'str'  => 'text',
        'val'  => ['a' => 1],
        default => ('get' === $op['o'] && 'length' === $op['p']) ? 3 : $op['o'],
    }));
    $sent = array_map(static function ($op) { unset($op['t'], $op['i']); return $op; }, ops($out['frames']));
    expect(array_slice($sent, 1))->toBe([
        ['o' => 'get', 'h' => 5, 'p' => 'width'],
        ['o' => 'set', 'h' => 5, 'p' => 'width', 'a' => [300]],
        ['o' => 'has', 'h' => 5, 'p' => 'width'],
        ['o' => 'del', 'h' => 5, 'p' => 'width'],
        ['o' => 'call', 'h' => 5, 'p' => 'fillRect', 'a' => [10, 10, 50, 50]],
        ['o' => 'call', 'h' => 5, 'p' => 'call', 'a' => [1]],
        ['o' => 'invoke', 'h' => 5, 'a' => [7]],
        ['o' => 'get', 'h' => 5, 'p' => 'k'],
        ['o' => 'set', 'h' => 5, 'p' => 'k', 'a' => ['v']],
        ['o' => 'has', 'h' => 5, 'p' => 'k'],
        ['o' => 'del', 'h' => 5, 'p' => 'k'],
        ['o' => 'get', 'h' => 5, 'p' => 'length'],
        ['o' => 'str', 'h' => 5],
        ['o' => 'val', 'h' => 5],
    ]);
    expect(replies($out['frames'])[1]['v'])->toBe(['get', true, 'call', 'call', 'invoke', 'get', true, 3, 'text', ['a' => 1]]);
});

test('a Promise is a JsObject that phasync::await() waits for', function () {
    $out = play(['script' => static function (Browser $b) {
        $p = $b->call('fetchSomething');

        return [get_class($p), phasync::await($p, 1.0)];
    }], answers(static fn ($op) => 'await' === $op['o'] ? 'done' : handle(6, 'p')));
    expect(replies($out['frames'])[1]['v'])->toBe(['Tether\JsPromise', 'done']);
    expect(ops($out['frames'])[1])->toMatchArray(['o' => 'await', 'h' => 6]);
});

test('two Promises started, then awaited, run at once', function () {
    $started = [];
    $out = play(['script' => static function (Browser $b) {
        $a = $b->call('fetch', 'a');
        $c = $b->call('fetch', 'b');
        $t = microtime(true);

        return [phasync::await($a, 1.0), phasync::await($c, 1.0), microtime(true) - $t];
    }], static function (array $op, Circuit $circuit) use (&$started) {
        if ('path' === $op['o']) {
            $started[$op['p'] . $op['a'][0]] = microtime(true);
            $circuit->returned($op['i'], handle('a' === $op['a'][0] ? 5 : 6, 'p'), null);

            return;
        }
        // A promise settles 0.1 s after it was started
        phasync::sleep(max(0, $started['fetch' . (5 === $op['h'] ? 'a' : 'b')] + 0.1 - microtime(true)));
        $circuit->returned($op['i'], 5 === $op['h'] ? 'A' : 'B', null);
    });
    $v = replies($out['frames'])[1]['v'];
    expect([$v[0], $v[1]])->toBe(['A', 'B']);
    expect($v[2])->toBeLessThan(0.2);
});

test('a rejected Promise, and a thrown error, are JsExceptions with the browser\'s name, message and stack', function () {
    $out = play(['script' => static function (Browser $b) {
        $r = [];
        try {
            $b->call('boom');
        } catch (JsException $e) {
            $r[] = [$e::class, $e->getMessage(), $e->jsName, $e->jsStack];
        }
        try {
            phasync::await($b->call('reject'), 1.0);
        } catch (JsException $e) {
            $r[] = [$e::class, $e->getMessage(), $e->jsName];
        }

        return $r;
    }], static function (array $op, Circuit $circuit) {
        $error = ['m' => 'bad', 'n' => 'TypeError', 's' => "TypeError: bad\n    at boom"];
        if ('boom' === ($op['p'] ?? null) || 'await' === $op['o']) {
            $circuit->returned($op['i'], null, $error);
        } else {
            $circuit->returned($op['i'], handle(5, 'p'), null);
        }
    });
    expect(replies($out['frames'])[1]['v'])->toBe([
        ['Tether\JsException', 'bad', 'TypeError', "TypeError: bad\n    at boom"],
        ['Tether\JsException', 'bad', 'TypeError'],
    ]);
});

test('text in an error from the browser is cut', function () {
    $out = play(['script' => static function (Browser $b) {
        try {
            $b->call('boom');
        } catch (JsException $e) {
            return strlen($e->getMessage());
        }
    }], static fn (array $op, Circuit $circuit) => $circuit->returned($op['i'], null, ['m' => str_repeat('x', 100000)]));
    expect(replies($out['frames'])[1]['v'])->toBe(4096);
});

test('a call that is not answered in time is a JsTimeoutException; the late answer is ignored and its objects released', function () {
    $ids = [];
    $out = play(['script' => static function (Browser $b) {
        try {
            $b->within(0.03, fn () => $b->call('slow'));
        } catch (JsTimeoutException $e) {
            return $e instanceof JsException;
        }
    }], static function (array $op) use (&$ids) { $ids[] = $op['i']; }, static function (Circuit $circuit, ArrayObject $frames) use (&$ids) {
        $circuit->returned($ids[0], handle(9), null);
        phasync::sleep(0.02);
    });
    expect(replies($out['frames'])[1]['v'])->toBeTrue();
    expect(array_merge(...array_map(static fn ($f) => $f['rel'] ?? [], $out['frames'])))->toBe([[9, 1]]);
});

test('the default timeout is Limits::$callTimeout', function () {
    $out = play(['script' => static function (Browser $b) {
        $t = microtime(true);
        try {
            $b->call('slow');
        } catch (JsTimeoutException) {
            return microtime(true) - $t;
        }
    }], null, null, ['limits' => new Limits(callTimeout: 0.04)]);
    expect(replies($out['frames'])[1]['v'])->toBeBetween(0.035, 0.1);
});

test('a tab that disconnects cancels the call that waits, which nothing can catch', function () {
    $log = [];
    play(['script' => static function (Browser $b) use (&$log) {
        try {
            $b->call('never');
        } catch (JsException) {
            $log[] = 'caught';
        } finally {
            $log[] = 'finally';
        }
    }], null, static fn (Circuit $circuit) => $circuit->close(), wait: 0.03);
    expect($log)->toBe(['finally']);
});

test('calls are matched to answers by id, in any order', function () {
    $out = play(['script' => static function (Browser $b) {
        $f = [phasync::go(fn () => $b->call('first')), phasync::go(fn () => $b->call('second'))];
        $t = microtime(true);

        return [phasync::await($f[0]), phasync::await($f[1]), microtime(true) - $t];
    }], static function (array $op, Circuit $circuit) {
        phasync::sleep('first' === $op['p'] ? 0.08 : 0.04);
        $circuit->returned($op['i'], $op['p'], null);
    });
    $v = replies($out['frames'])[1]['v'];
    expect([$v[0], $v[1]])->toBe(['first', 'second']);
    expect($v[2])->toBeLessThan(0.12);
});

test('a call waits for its answer, and the component\'s other coroutines go on', function () {
    $log = [];
    play(['script' => static function (Browser $b, Scripted $self) use (&$log) {
        phasync::go(static function () use (&$log) { phasync::sleep(0.01); $log[] = 'other'; });
        $b->call('slow');
        $log[] = 'answered';
    }], static function (array $op, Circuit $circuit) {
        phasync::sleep(0.04);
        $circuit->returned($op['i'], null, null);
    });
    expect($log)->toBe(['other', 'answered']);
});

test('the tab holds at most Limits::$calls calls at once', function () {
    $out = play(['script' => static function (Browser $b) {
        $f = [phasync::go(static function () use ($b) { try { $b->call('a'); } catch (JsException) { } }), phasync::go(static function () use ($b) { try { $b->call('b'); } catch (JsException) { } })];
        try {
            $b->call('c');
        } catch (JsLimitException $e) {
            return [get_class($e), 2 === count($f)];
        }
    }], null, null, ['limits' => new Limits(calls: 2, callTimeout: 0.05)]);
    expect(replies($out['frames'])[1]['v'][0])->toBe('Tether\JsLimitException');
    expect(ops($out['frames']))->toHaveCount(2);
});

test('an answer to nothing this tab asked is abuse, as an event is', function () {
    $abuse = 0;
    live(Scripted::class, [], static function (Circuit $circuit) {
        for ($i = 0; $i < 5; ++$i) {
            $circuit->returned(900 + $i, 1, null);
        }
    }, ['limits' => new Limits(eventsPerSecond: 1, burst: 2), 'abuse' => static function () use (&$abuse) { ++$abuse; }]);
    expect($abuse)->toBeGreaterThan(0);
});

test('answers to the server\'s own ops do not draw on the event budget', function () {
    $abuse = 0;
    $out = play(['script' => static function (Browser $b) {
        for ($i = 0; $i < 6; ++$i) {
            $b->call('n', $i);
        }

        return 'done';
    }], answers(1), null, ['limits' => new Limits(eventsPerSecond: 1, burst: 3), 'abuse' => static function () use (&$abuse) { ++$abuse; }]);
    expect($abuse)->toBe(0);
    expect(replies($out['frames'])[1]['v'])->toBe('done');
});

test('a JsObject is released to the browser when it is destroyed, once, with the references the browser gave', function () {
    $out = play(['script' => static function (Browser $b) {
        $a = $b->call('make');
        $c = $b->call('make');
        unset($a);
        phasync::sleep(0.02);
        unset($c);
        phasync::sleep(0.02);
    }], answers(handle(5)));
    $rel = array_merge(...array_map(static fn ($f) => $f['rel'] ?? [], $out['frames']));
    // The same object came twice, as one JsObject holding two references
    expect($rel)->toBe([[5, 2]]);
});

test('a JsObject is stale when its component has left the page, and released then', function () {
    $kept = null;
    $out = live(Shower::class, ['childProps' => ['script' => static function (Browser $b) use (&$kept) { $kept = $b->call('make'); }]], static function (Circuit $circuit) use (&$kept) {
        $circuit->event('c1', 'show', []);
        phasync::sleep(0.03);
        $circuit->event('c2', 'play', []);
        phasync::sleep(0.03);
        expect($kept)->toBeInstanceOf(JsObject::class);
        $circuit->event('c1', 'hide', []);
        phasync::sleep(0.05);
        expect(fn () => $kept->name)->toThrow(JsStaleException::class);
    }, [], answers(handle(5)));
    $rel = array_merge(...array_map(static fn ($f) => $f['rel'] ?? [], $out['frames']));
    expect($rel)->toBe([[5, 1]]);
    expect($out['crashed'])->toBeNull();
});

test('executeString has named variables, and values are never code', function () {
    $evil = "'); alert(1); ('";
    $out = play(['script' => static function (Browser $b) use ($evil) {
        $b->executeString('return document.getElementById(id) || text;', ['id' => $evil, 'text' => 'x']);
        try {
            $b->executeString('return 1', ['a b' => 1]);
        } catch (InvalidArgumentException) {
            return 'refused';
        }
    }], answers());
    $op = ops($out['frames'])[0];
    expect($op)->toMatchArray(['o' => 'eval', 'p' => 'return document.getElementById(id) || text;', 'n' => ['id', 'text'], 'a' => [$evil, 'x']]);
    expect(replies($out['frames'])[1]['v'])->toBe('refused');
    expect(ops($out['frames']))->toHaveCount(1);
});

test('arguments that are not data are refused before anything is sent', function () {
    $out = play(['script' => static function (Browser $b) {
        $r = [];
        foreach ([static fn () => 1, ['$' => 'h', 'id' => 0]] as $arg) {
            try {
                $b->call('f', $arg);
            } catch (InvalidArgumentException $e) {
                $r[] = $e::class;
            }
        }

        return $r;
    }], answers());
    expect(replies($out['frames'])[1]['v'])->toHaveCount(2);
    expect(ops($out['frames']))->toBe([]);
});

test('only #[Invokable] handlers give their result to the browser', function () {
    $out = live(Scripted::class, [], static function (Circuit $circuit) {
        expect(fn () => $circuit->event('c1', 'notInvokable', [], reply: 1, value: true))->toThrow(InvalidArgumentException::class);
        $circuit->event('c1', 'notInvokable', [], reply: 2);
        phasync::sleep(0.03);
    });
    expect(replies($out['frames'])[1])->toHaveKey('e');
    expect(replies($out['frames'])[2])->toBe(['r' => 2, 'v' => null]);
});

test('calls into the browser are refused in mount(), and before the tab is live', function () {
    expect(fn () => phasync::run(function () {
        (new Circuit())->mount(Scripted::class, ['onMount' => static fn (Scripted $s) => (fn () => $this->browser()->call('x'))->call($s)]);
    }))->toThrow(LogicException::class);
});

test('a component\'s first call waits until the browser has its HTML', function () {
    $out = live(Shower::class, ['childProps' => ['onRun' => static fn (Browser $b) => $b->call('first')]], static function (Circuit $circuit) {
        $circuit->event('c1', 'show', []);
        phasync::sleep(0.05);
    }, [], answers());
    $kinds = array_map(static fn ($f) => 'op' === $f['t'] ? 'op' : (isset($f['patches']) ? 'patch ' . implode(',', array_column($f['patches'], 'id')) : $f['t']), $out['frames']);
    expect(array_slice($kinds, 0, 2))->toBe(['patch c1', 'op']);
});

test('awaitRender() puts the new HTML in the browser before the call that needs it', function () {
    $out = play(['script' => static fn (Browser $b) => $b->call('read')], answers(), event: 'bump');
    expect(array_map(static fn ($f) => $f['t'], $out['frames']))->toBe(['frame', 'op', 'frame']);
    expect($out['frames'][0]['patches'][0]['html'])->toBe('<div tether-id="c1">1</div>');
});

test('a call does not send a pending render', function () {
    $out = play(['script' => static fn (Browser $b) => $b->call('read')], answers(), event: 'bumpAndCall');
    expect(array_map(static fn ($f) => $f['t'], $out['frames']))->toBe(['op', 'frame']);
});

test('new, import, hook, find and ref are ops with the component\'s id and names as data', function () {
    $out = play(['script' => static function (Browser $b) {
        $b->new('Audio', '/a.mp3');
        $b->import('/m.js');
        $b->hook('Stopwatch');
        $b->find('#x');
        $b->find();
        $b->ref('canvas');
        try {
            $b->ref('a"] , [x');
        } catch (InvalidArgumentException) {
            return 'refused';
        }
    }], answers(handle(5)));
    expect(array_map(static fn ($op) => array_diff_key($op, ['t' => 0, 'i' => 0]), ops($out['frames'])))->toBe([
        ['o' => 'new', 'p' => 'Audio', 'a' => ['/a.mp3']],
        ['o' => 'import', 'p' => '/m.js'],
        ['o' => 'hook', 'c' => 'c1', 'p' => 'Stopwatch'],
        ['o' => 'find', 'c' => 'c1', 'p' => '#x'],
        ['o' => 'find', 'c' => 'c1', 'p' => null],
        ['o' => 'find', 'c' => 'c1', 'p' => '[tether-ref="canvas"]'],
    ]);
    expect(replies($out['frames'])[1]['v'])->toBe('refused');
});

test('the tab holds at most Limits::$handles objects of the browser\'s', function () {
    $next = 1;
    $out  = play(['script' => static function (Browser $b) {
        $held = [$b->call('a'), $b->call('b')];
        try {
            $b->call('c');
        } catch (JsLimitException) {
            return count($held);
        }
    }], answers(static function () use (&$next) { return handle(++$next); }), null, ['limits' => new Limits(handles: 2)]);
    expect(replies($out['frames'])[1]['v'])->toBe(2);
});
