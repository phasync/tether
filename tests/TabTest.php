<?php

use Tether\Component;
use Tether\Event\KeyboardEventArgs;
use Tether\Circuit;
use Tether\Invokable;
use Tether\Limits;
use Tether\NoRender;
use Tether\JsException;
use Tether\Tether;
use Tether\Testing\Tab;

final class TabCounter extends Component
{
    public int $count = 0;

    public function increment(int $by = 1): void
    {
        $this->count += $by;
    }

    public function key(KeyboardEventArgs $e): void
    {
        $this->count += 'Enter' === $e->key ? 100 : 0;
    }

    #[Invokable]
    public function double(): int
    {
        return $this->count * 2;
    }

    public function explode(): void
    {
        throw new RuntimeException('boom');
    }

    #[Invokable]
    public function width(): mixed
    {
        return $this->browser()->window->innerWidth;
    }

    public function run(): void
    {
        phasync::sleep(0.03);
        $this->count = -1; // changed without requestRender(): the browser is not told
    }

    public function render(): string
    {
        return "<p class=\"n\">{$this->count}</p>";
    }
}

final class TabItem extends Component
{
    public string $label = '';

    public bool $done = false;

    public function toggle(): void
    {
        $this->done = !$this->done;
    }

    public function render(): string
    {
        return '<li>' . ($this->done ? 'x ' : '') . htmlspecialchars($this->label) . '</li>';
    }
}

final class TabList extends Component
{
    public int $renders = 0;

    public function render(): string
    {
        ++$this->renders;

        return '<ul>' . $this->child(TabItem::class, ['label' => 'a'], 'a') . $this->child(TabItem::class, ['label' => 'b'], 'b') . '</ul>';
    }
}

test('mount renders the page; an event runs its handler and the tab is idle when the new HTML is in', function () {
    Tab::mount(TabCounter::class, ['count' => 3], function (Tab $tab) {
        expect($tab->html())->toBe('<p tether-id="c1" class="n">3</p>');
        $tab->call('increment', [4]);
        expect($tab->html())->toBe('<p tether-id="c1" class="n">7</p>');
        $tab->call('increment');
        expect($tab->html())->toContain('>8<');
    });
});

test('a child that renders alone shows in the root\'s HTML, and html($id) gives one component', function () {
    Tab::mount(TabList::class, [], function (Tab $tab) {
        expect($tab->html())->toBe('<ul tether-id="c1"><li tether-id="c2">a</li><li tether-id="c3">b</li></ul>');
        $tab->call('toggle', id: 'c3');
        expect($tab->html())->toBe('<ul tether-id="c1"><li tether-id="c2">a</li><li tether-id="c3">x b</li></ul>')
            ->and($tab->html('c3'))->toBe('<li tether-id="c3">x b</li>')
            ->and($tab->frames[0]['patches'])->toHaveCount(1);
    });
});

test('the browser\'s refusals apply: not a handler, wrong arguments', function (string $method, array $args, string $id) {
    Tab::mount(TabCounter::class, [], function (Tab $tab) use ($method, $args, $id) {
        expect(fn () => $tab->call($method, $args, $id))->toThrow(InvalidArgumentException::class);
    });
})->with([
    'render'     => ['render', [], 'c1'],
    'a missing'  => ['nope', [], 'c1'],
    'a string'   => ['increment', ['5'], 'c1'],
    'too many'   => ['increment', [1, 2], 'c1'],
]);

test('an event for a component that is not in the page is an error in the test, not ignored as in the browser', function () {
    Tab::mount(TabCounter::class, [], function (Tab $tab) {
        expect(fn () => $tab->call('increment', id: 'c9'))->toThrow(LogicException::class, 'No component c9');
    });
});

test('event data goes to a handler typed with its event class', function () {
    Tab::mount(TabCounter::class, [], function (Tab $tab) {
        $tab->call('key', payload: ['type' => 'keydown', 'key' => 'Enter']);
        expect($tab->html())->toContain('>100<');
    });
});

test('invoke gives an Invokable handler\'s return value; one that is not Invokable is refused', function () {
    Tab::mount(TabCounter::class, ['count' => 21], function (Tab $tab) {
        expect($tab->invoke('double'))->toBe(42);
        expect(fn () => $tab->invoke('increment'))->toThrow(InvalidArgumentException::class);
    });
});

test('a scripted browser answers the calls into it', function () {
    $ops = [];
    $answers = static function (array $op) use (&$ops) {
        $ops[] = $op;

        return 1024;
    };
    expect(Tab::mount(TabCounter::class, [], fn (Tab $tab) => $tab->invoke('width'), browser: $answers))->toBe(1024)
        ->and($ops[0])->toMatchArray(['t' => 'op']);
});

test('a scripted browser that throws a JsException is a JavaScript error: the handler fails and the tab crashes', function () {
    $fails = static fn () => throw new JsException('no window', 'TypeError');
    Tab::mount(TabCounter::class, [], function (Tab $tab) {
        expect(fn () => $tab->invoke('width'))->toThrow(JsException::class, 'no window');
        expect($tab->crashed)->toBeInstanceOf(JsException::class);
        expect(fn () => $tab->html())->toThrow(JsException::class);
    }, browser: $fails);
});

test('a handler that fails crashes the tab: crashed is set, and html() throws it', function () {
    Tab::mount(TabCounter::class, [], function (Tab $tab) {
        $tab->call('explode');
        expect($tab->crashed?->getMessage())->toBe('boom');
        expect(fn () => $tab->html())->toThrow(RuntimeException::class, 'boom');
    });
});

test('advance lets run() work, and what it changed without requestRender() is not in the HTML', function () {
    Tab::mount(TabCounter::class, ['count' => 5], function (Tab $tab) {
        $tab->advance(0.06);
        expect($tab->html())->toContain('>5<');
    });
});

test('a page closure runs as it does for a live connection, with the request', function () {
    $seen = [];
    $request = new phasync\Psr\ServerRequest('GET', '/n/7', new phasync\Psr\StringStream(''), ['Host' => 'example.test']);
    Tab::page(function (Tether $t) use (&$seen) {
        $seen[] = $t->live;

        return $t->mount(TabCounter::class, ['count' => 9], 'Nine');
    }, function (Tab $tab) {
        $tab->call('increment');
        expect($tab->html())->toContain('>10<');
    }, request: $request);
    expect($seen)->toBe([true]);
});

test('a page closure that returns anything but a Page is an error', function () {
    expect(fn () => Tab::page(fn () => 'nope', fn () => null))->toThrow(LogicException::class);
});

final class TabLinks extends Component
{
    public string $handler = 'save';

    public function save(): void
    {
    }

    private function hidden(): void
    {
    }

    public function render(): string
    {
        return "<div><button tether-click=\"save\">a</button><input tether-on-keydown.document.key-slash.nofield=\"{$this->handler}\" tether-args=\"[1]\"></div>";
    }
}

test('assertHandlers passes when every handler named in the markup is one the browser may call', function () {
    Tab::mount(TabLinks::class, [], fn (Tab $tab) => $tab->assertHandlers());
    expect(true)->toBeTrue();
});

test('assertHandlers fails for a misspelled, a private and a missing handler, naming the attribute', function (string $handler) {
    Tab::mount(TabLinks::class, ['handler' => $handler], function (Tab $tab) use ($handler) {
        expect(fn () => $tab->assertHandlers())->toThrow(LogicException::class, "=\"$handler\"");
    });
})->with(['goo', 'hidden', 'render']);

final class TabQuiet extends Component
{
    public int $count = 0;

    public int $renders = 0;

    public function touch(): void
    {
        $this->requestRender();
    }

    public function bump(): void
    {
        ++$this->count;
    }

    #[NoRender]
    public function tally(): void
    {
        ++$this->count;
    }

    public function render(): string
    {
        ++$this->renders;

        return "<p>{$this->count}</p>";
    }
}

test('a render that gives the HTML the browser has sends no patch', function () {
    Tab::mount(TabQuiet::class, [], function (Tab $tab) {
        $before = count($tab->frames);
        $tab->call('touch');
        expect($tab->frames)->toHaveCount($before);
        $tab->call('bump');
        expect(count($tab->frames))->toBe($before + 1)->and($tab->html())->toContain('>1<');
    });
});

test('an event of a form field gets its patch even when the HTML is the one the browser has: that is what resets the field', function (string $type, bool $shipped) {
    Tab::mount(TabQuiet::class, [], function (Tab $tab) use ($type, $shipped) {
        $before = count($tab->frames);
        $tab->call('touch', payload: ['type' => $type]);
        expect(count($tab->frames))->toBe($before + ($shipped ? 1 : 0));
        if ($shipped) {
            expect($tab->frames[$before]['patches'][0]['html'])->toBe($tab->html());
        }
    });
})->with([['click', false], ['mouseenter', false], ['input', true], ['change', true], ['submit', true], ['keydown', true], ['keyup', true]]);

test('a handler marked NoRender does not render the component', function () {
    Tab::mount(TabQuiet::class, [], function (Tab $tab) {
        $before = count($tab->frames);
        $tab->call('tally');
        expect($tab->frames)->toHaveCount($before)->and($tab->html())->toContain(">0<");
        $tab->call('touch');
        expect($tab->html())->toContain('>1<');
    });
});

final class TabFlip extends Component
{
    public bool $on = false;

    public function flip(): void
    {
        $this->on = !$this->on;
    }

    public function render(): string
    {
        return '<div>' . $this->child(TabFlipLeaf::class, ['on' => $this->on]) . '</div>';
    }
}

final class TabFlipLeaf extends Component
{
    public bool $on = false;

    public bool $forced = false;

    public function force(): void
    {
        $this->forced = true;
    }

    public function propsChanged(array $old): void
    {
        $this->forced = false;
    }

    public function render(): string
    {
        return '<i>' . ($this->forced ? 'B' : 'A') . '</i>';
    }
}

test('a child patched to new HTML and rendered back to the old one by its parent is sent again', function () {
    Tab::mount(TabFlip::class, [], function (Tab $tab) {
        $tab->call('force', [], 'c2');
        expect($tab->html())->toContain('>B<');
        $tab->call('flip');
        expect($tab->html())->toContain('>A<');
    });
});

test('a heartbeat is answered with a pong, and takes nothing from the event bucket', function () {
    Tab::mount(TabCounter::class, [], function (Tab $tab) {
        for ($i = 0; $i < 10; ++$i) {
            $tab->ping();
        }
        $tab->call('increment');
        expect(array_column($tab->frames, 't'))->toBe(array_merge(array_fill(0, 10, 'pong'), ['frame']))
            ->and($tab->timedOut)->toBeFalse();
    }, limits: new Limits(eventsPerSecond: 1, burst: 3));
});

test('a tab that sent nothing for clientTimeout is closed; anything the browser sends starts the count again', function () {
    Tab::mount(TabCounter::class, [], function (Tab $tab) {
        $tab->advance(0.15);
        $tab->ping();
        $tab->advance(0.15);
        $tab->call('increment');
        $tab->advance(0.15);
        expect($tab->timedOut)->toBeFalse();
        $tab->advance(0.1);
        expect($tab->timedOut)->toBeTrue();
    }, limits: new Limits(clientTimeout: 0.2));
});
