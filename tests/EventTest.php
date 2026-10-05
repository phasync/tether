<?php

use Tether\Circuit;
use Tether\Component;
use Tether\Event\ChangeEventArgs;
use Tether\Event\EventArgs;
use Tether\Event\KeyboardEventArgs;
use Tether\Event\MouseEventArgs;
use Tether\Event\PointerEventArgs;
use Tether\Event\WheelEventArgs;
use Tether\Limits;

/** Handlers taking what the browser says about the event. */
final class EventSink extends Component
{
    public static array $got = [];

    public static int $running = 0;

    public static int $peak = 0;

    public function typed(int $n, KeyboardEventArgs $e): void
    {
        self::$got[] = ['typed', $n, $e];
    }

    public function raw(EventArgs $e): void
    {
        self::$got[] = ['raw', $e];
    }

    public function optional(int $n = 1, ?MouseEventArgs $e = null): void
    {
        self::$got[] = ['optional', $n, $e];
    }

    /** An array parameter keeps its meaning: it is not where the payload goes. */
    public function plain(int $id, array $options = []): void
    {
        self::$got[] = ['plain', $id, $options];
    }

    public function none(): void
    {
        self::$got[] = ['none'];
    }

    public function field(string $value): void
    {
        self::$got[] = ['field', $value];
    }

    public function changed(ChangeEventArgs $e): void
    {
        self::$got[] = ['changed', $e->value];
    }

    public function tagged(string $tag, string $value): void
    {
        self::$got[] = ['tagged', $tag, $value];
    }

    public function slow(): void
    {
        self::$peak = max(self::$peak, ++self::$running);
        try {
            phasync::sleep(0.1);
        } finally {
            --self::$running;
        }
    }

    public function render(): string
    {
        return '<p>sink</p>';
    }
}

/** Call $fn: the refusal the browser's mistake is answered with is an exception too. */
function attempt(Closure $fn): void
{
    try {
        $fn();
    } catch (InvalidArgumentException) {
    }
}

beforeEach(function () {
    EventSink::$got     = [];
    EventSink::$running = EventSink::$peak = 0;
});

test('EventArgs::from fills the constructor from the payload, with defaults for what is missing', function () {
    $e = KeyboardEventArgs::from(['type' => 'keydown', 'key' => 'a', 'shiftKey' => true, 'extra' => 1]);
    expect($e->type)->toBe('keydown')
        ->and($e->key)->toBe('a')
        ->and($e->code)->toBe('')
        ->and($e->shiftKey)->toBeTrue()
        ->and($e->repeat)->toBeFalse()
        ->and($e->data)->toBe(['type' => 'keydown', 'key' => 'a', 'shiftKey' => true, 'extra' => 1]);
});

test('an int is accepted where a float is declared, a string is not', function () {
    expect(MouseEventArgs::from(['clientX' => 12, 'clientY' => 3.5])->clientX)->toBe(12.0);
    expect(fn () => MouseEventArgs::from(['clientX' => '12']))->toThrow(InvalidArgumentException::class);
    expect(fn () => MouseEventArgs::from(['button' => 1.5]))->toThrow(InvalidArgumentException::class);
    expect(fn () => KeyboardEventArgs::from(['repeat' => 1]))->toThrow(InvalidArgumentException::class);
});

test('a subclass of a mouse event takes the mouse payload as well as its own', function () {
    $e = PointerEventArgs::from(['type' => 'pointermove', 'pointerId' => 3, 'pointerType' => 'pen', 'clientX' => 5, 'buttons' => 1, 'altKey' => true]);
    expect($e)->toBeInstanceOf(MouseEventArgs::class)
        ->and($e->pointerId)->toBe(3)
        ->and($e->pointerType)->toBe('pen')
        ->and($e->clientX)->toBe(5.0)
        ->and($e->buttons)->toBe(1)
        ->and($e->altKey)->toBeTrue();
    $w = WheelEventArgs::from(['deltaY' => 120, 'clientY' => 9]);
    expect($w->deltaY)->toBe(120.0)->and($w->clientY)->toBe(9.0);
});

test('the change event takes any value the browser reads from a field', function () {
    expect(ChangeEventArgs::from(['value' => 3])->value)->toBe(3)
        ->and(ChangeEventArgs::from(['value' => ['a', 'b']])->value)->toBe(['a', 'b'])
        ->and(ChangeEventArgs::from(['value' => null])->value)->toBeNull()
        ->and(ChangeEventArgs::from(['value' => true])->value)->toBeTrue();
});

test('the payload goes only into a last parameter typed EventArgs, after the arguments', function () {
    live(EventSink::class, [], function (Circuit $c) {
        $c->event('c1', 'typed', [7], null, ['type' => 'keydown', 'key' => 'Enter']);
        $c->event('c1', 'raw', [], null, ['type' => 'visibilitychange', 'hidden' => true]);
        phasync::sleep(0.02);
    });
    [$typed, $raw] = EventSink::$got;
    expect($typed[1])->toBe(7)
        ->and($typed[2])->toBeInstanceOf(KeyboardEventArgs::class)
        ->and($typed[2]->key)->toBe('Enter')
        ->and($raw[1]::class)->toBe(EventArgs::class)
        ->and($raw[1]->data)->toBe(['type' => 'visibilitychange', 'hidden' => true]);
});

test('optional parameters before the event keep their defaults', function () {
    live(EventSink::class, [], function (Circuit $c) {
        $c->event('c1', 'optional', [], null, ['type' => 'click', 'clientX' => 4]);
        $c->event('c1', 'optional', [5], null, ['type' => 'click']);
    });
    expect(EventSink::$got[0][1])->toBe(1)
        ->and(EventSink::$got[0][2]->clientX)->toBe(4.0)
        ->and(EventSink::$got[1][1])->toBe(5);
});

test('a field\'s value fills the parameters tether-args left, and is dropped when the handler has none', function () {
    live(EventSink::class, [], function (Circuit $c) {
        $c->event('c1', 'field', [], null, [], false, ['text']);
        $c->event('c1', 'tagged', ['a'], null, [], false, ['text']);
        $c->event('c1', 'none', [], null, [], false, ['text']);
        $c->event('c1', 'changed', [], null, ['type' => 'input', 'value' => 'text'], false, ['text']);
        $c->event('c1', 'typed', [7], null, ['type' => 'keydown'], false, ['text']);
    });
    expect(EventSink::$got)->toBe([['field', 'text'], ['tagged', 'a', 'text'], ['none'], ['changed', 'text'], ['typed', 7, EventSink::$got[4][2]]]);
});

test('a parameter the arguments and the field leave unfilled is still refused', function () {
    $r = live(EventSink::class, [], function (Circuit $c) {
        attempt(fn () => $c->event('c1', 'tagged', [], 1, [], false, ['text']));
        attempt(fn () => $c->event('c1', 'field', [], 2, [], false, []));
    });
    expect(EventSink::$got)->toBe([])->and(array_keys(replies($r['frames'])))->toBe([1, 2]);
});

test('a trailing array parameter is never given the payload, and no typed parameter ignores it', function () {
    live(EventSink::class, [], function (Circuit $c) {
        $c->event('c1', 'plain', [5], null, ['type' => 'click', 'clientX' => 1]);
        $c->event('c1', 'plain', [5, ['x' => 1]], null, ['type' => 'click']);
        $c->event('c1', 'none', [], null, ['type' => 'click']);
    });
    expect(EventSink::$got)->toBe([['plain', 5, []], ['plain', 5, ['x' => 1]], ['none']]);
});

test('the event is not one of the arguments the browser passes', function () {
    $frames = live(EventSink::class, [], function (Circuit $c) {
        attempt(fn () => $c->event('c1', 'typed', [], 1, ['type' => 'keydown']));
        attempt(fn () => $c->event('c1', 'typed', [1, ['key' => 'x']], 2, ['type' => 'keydown']));
    })['frames'];
    expect(EventSink::$got)->toBe([])
        ->and(array_keys(replies($frames)))->toBe([1, 2])
        ->and(replies($frames)[1])->toHaveKey('e');
});

test('a payload that does not fit the class is refused, with a reply when the browser waits', function () {
    $r = live(EventSink::class, [], function (Circuit $c) {
        attempt(fn () => $c->event('c1', 'typed', [1], 4, ['type' => 'keydown', 'key' => 5]));
    });
    expect(EventSink::$got)->toBe([])
        ->and(replies($r['frames'])[4])->toHaveKey('e')
        ->and($r['crashed'])->toBeNull();
});

test('a refused event with no reply to carry it is in the next frame', function () {
    $r = live(EventSink::class, [], function (Circuit $c) {
        attempt(fn () => $c->event('c1', 'missing', [], null, []));
        attempt(fn () => $c->event('c1', 'typed', ['no'], null, []));
    });
    $refused = array_merge(...array_map(fn ($f) => $f['refused'] ?? [], $r['frames']));
    expect($refused)->toBe([
        ['m' => 'missing', 'e' => 'EventSink::missing() is not an event handler'],
        ['m' => 'typed', 'e' => 'EventSink::typed(): argument $n must be int, not string'],
    ]);
});

test('an empty bucket is abuse: the abuse callback is told and the event does not run', function () {
    $abused = 0;
    live(EventSink::class, [], function (Circuit $c) {
        for ($i = 0; $i < 5; ++$i) {
            $c->event('c1', 'none', [], null, []);
        }
    }, ['limits' => new Limits(eventsPerSecond: 1, burst: 3), 'abuse' => function () use (&$abused) { ++$abused; }]);
    expect(EventSink::$got)->toHaveCount(3)->and($abused)->toBe(2);
});

test('the bucket refills', function () {
    $abused = 0;
    live(EventSink::class, [], function (Circuit $c) {
        $c->event('c1', 'none', [], null, []);
        $c->event('c1', 'none', [], null, []);
        phasync::sleep(0.12);
        $c->event('c1', 'none', [], null, []);
    }, ['limits' => new Limits(eventsPerSecond: 20, burst: 2), 'abuse' => function () use (&$abused) { ++$abused; }]);
    expect(EventSink::$got)->toHaveCount(3)->and($abused)->toBe(0);
});

test('past the running limit an event is refused with an error reply, and the tab goes on', function () {
    $r = live(EventSink::class, [], function (Circuit $c) {
        $c->event('c1', 'slow', [], 1);
        $c->event('c1', 'slow', [], 2);
        $c->event('c1', 'slow', [], 3);
        $c->event('c1', 'slow', [], null);
        phasync::sleep(0.15);
        // Counted out: the tab takes events again
        $c->event('c1', 'slow', [], 4);
        phasync::sleep(0.15);
    }, ['limits' => new Limits(running: 2)]);
    $replies = replies($r['frames']);
    expect($replies[1])->toHaveKey('v')
        ->and($replies[2])->toHaveKey('v')
        ->and($replies[3])->toHaveKey('e')
        ->and($replies[4])->toHaveKey('v')
        ->and(EventSink::$peak)->toBe(2)
        ->and($r['crashed'])->toBeNull();
    expect(array_merge(...array_map(fn ($f) => $f['refused'] ?? [], $r['frames'])))->toBe([['m' => 'slow', 'e' => 'The tab has too many events running']]);
});

test('handlers cancelled by an unmount are counted out of the running limit', function () {
    $r = live(EventSink::class, [], function (Circuit $c) {
        $c->event('c1', 'slow', []);
        $c->event('c1', 'slow', []);
        phasync::sleep(0.01);
        $c->close();
        phasync::sleep(0.01);

        return (new ReflectionProperty(Circuit::class, 'running'))->getValue($c);
    }, ['limits' => new Limits(running: 2)]);
    expect($r['crashed'])->toBeNull()->and($r['result'])->toBe(0);
});

test('navigate with $replace replaces the history entry', function () {
    $r = live(NavigatingSink::class, [], function (Circuit $c) {
        $c->event('c1', 'visit', ['/b', false]);
        phasync::sleep(0.05);
        $c->event('c1', 'visit', ['/c', true]);
    }, ['resolve' => fn (string $url) => [new Tether\Page(NavigatingSink::class, [], 'T'), $url]]);
    $navs = array_values(array_filter(array_column($r['frames'], 'nav')));
    expect($navs[0]['u'])->toBe('/b')->and($navs[0]['p'])->toBeTrue()
        ->and($navs[1]['u'])->toBe('/c')->and($navs[1]['p'])->toBeFalse();
});

final class NavigatingSink extends Component
{
    public function visit(string $url, bool $replace): void
    {
        $this->navigate($url, $replace);
    }

    public function render(): string
    {
        return '<p>nav</p>';
    }
}
