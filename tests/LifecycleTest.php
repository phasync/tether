<?php

use Tether\Circuit;
use Tether\Component;
use Tether\Testing\Tab;

final class LifeChild extends Component
{
    public static array $log = [];

    public int $n = 0;

    public ?stdClass $box = null;

    public bool $failDispose = false;

    public bool $failProps = false;

    public function mount(): void
    {
        self::$log[] = "mount {$this->n}";
    }

    public function propsChanged(array $old): void
    {
        self::$log[] = "props {$old['n']}>{$this->n}";
        if ($this->failProps) {
            throw new RuntimeException('props');
        }
    }

    public function dispose(): void
    {
        self::$log[] = "dispose {$this->n}";
        if ($this->failDispose) {
            throw new RuntimeException('dispose');
        }
    }

    public function render(): string
    {
        return '<i>' . ($this->box?->n ?? $this->n) . ($this->isLive() ? ' live' : ' static') . $this->e('"<') . '</i>';
    }
}

final class LifeParent extends Component
{
    public int $n = 0;

    public bool $show = true;

    public bool $failDispose = false;

    public bool $failProps = false;

    public stdClass $box;

    public function mount(): void
    {
        $this->box = new stdClass();
        $this->box->n = 0;
    }

    public function bump(): void
    {
        ++$this->n;
    }

    public function hide(): void
    {
        $this->show = false;
    }

    public function mutate(): void
    {
        ++$this->box->n;
    }

    public function render(): string
    {
        return '<div>' . ($this->show ? $this->child(LifeChild::class, ['n' => $this->n, 'failDispose' => $this->failDispose, 'failProps' => $this->failProps]) : '')
            . $this->child(LifeChild::class, ['n' => 100, 'box' => $this->box], 'boxed') . '</div>';
    }
}

beforeEach(fn () => LifeChild::$log = []);

test('propsChanged() runs before the render when the parent gave different props, and not otherwise', function () {
    Tab::mount(LifeParent::class, [], function (Tab $tab) {
        $tab->call('bump');
        expect(LifeChild::$log)->toContain('props 0>1');
        LifeChild::$log = [];
        $tab->call('mutate'); // the parent renders again, the child's props are the same
        expect(LifeChild::$log)->not->toContain('props 1>1');
    });
});

test('a failure in propsChanged() is the child\'s: the tab crashes when no boundary is above it', function () {
    Tab::mount(LifeParent::class, ['failProps' => true], function (Tab $tab) {
        $tab->call('bump');
        expect($tab->crashed)->toBeInstanceOf(RuntimeException::class);
    });
});

test('an object among the props counts as changed: it may have changed inside', function () {
    Tab::mount(LifeParent::class, [], function (Tab $tab) {
        expect($tab->html())->toContain('<i tether-id="c3">0 live');
        $tab->call('mutate');
        expect($tab->html())->toContain('<i tether-id="c3">1 live');
    });
});

test('dispose() runs once when the child leaves the page, and for every component when the tab closes', function () {
    Tab::mount(LifeParent::class, [], function (Tab $tab) {
        $tab->call('hide');
        expect(array_count_values(LifeChild::$log)['dispose 0'])->toBe(1);
        LifeChild::$log = [];
    });
    expect(LifeChild::$log)->toBe(['dispose 100']);
});

test('a dispose() that throws is logged and does not stop the others, or fail the tab', function () {
    Tab::mount(LifeParent::class, ['failDispose' => true], function (Tab $tab) {
        $tab->call('hide');
        expect($tab->crashed)->toBeNull()->and(LifeChild::$log)->toContain('dispose 0');
    });
    expect(LifeChild::$log)->toContain('dispose 100');
});

test('a component is unmounted after the first HTML of a page: dispose() runs, and isLive() is false', function () {
    $html = Circuit::prerender(LifeParent::class, []);
    expect($html)->toContain('0 static')->and(LifeChild::$log)->toBe(['mount 0', 'mount 100', 'dispose 0', 'dispose 100']);
});

test('e() escapes for text and attributes', function () {
    expect(Circuit::prerender(LifeParent::class, []))->toContain('&quot;&lt;');
});

final class LeadingComment extends Component
{
    public function render(): string
    {
        return "<!-- a comment -->\n<div>x</div>";
    }
}

test('a render that does not start with an element names what it started with', function () {
    expect(fn () => Circuit::prerender(LeadingComment::class, []))
        ->toThrow(LogicException::class, 'must start with an element, not "<!-- a comment -->\n<div>x</div>"');
});
