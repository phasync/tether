<?php

use Tether\Bind;
use Tether\Component;
use Tether\Testing\Tab;

enum Plan: string
{
    case Free = 'free';
    case Pro  = 'pro';
}

enum Level: int
{
    case Low  = 1;
    case High = 2;
}

final class BindForm extends Component
{
    #[Bind]
    public string $name = '';

    #[Bind]
    public ?int $age = null;

    #[Bind]
    public float $price = 0.0;

    #[Bind]
    public bool $subscribed = false;

    #[Bind]
    public Plan $plan = Plan::Free;

    #[Bind]
    public ?Level $level = null;

    /** @var list<string> */
    #[Bind]
    public array $tags = [];

    public string $secret = 'x';

    public function render(): string
    {
        return '<form><input ' . $this->bind('name') . '><input ' . $this->bind('age') . '>'
            . '<input type="checkbox" ' . $this->bind('subscribed') . '>'
            . '<input type="radio" ' . $this->bind('plan', 'free') . '><input type="radio" ' . $this->bind('plan', 'pro') . '>'
            . '<b id="s">' . $this->e("{$this->name}|{$this->age}|{$this->price}|" . var_export($this->subscribed, true) . "|{$this->plan->value}|{$this->level?->value}|" . implode(',', $this->tags)) . '</b></form>';
    }
}

final class BindBroken extends Component
{
    public string $which = 'plain';

    public string $plain = '';

    #[Bind]
    public int|string $union = 1;

    public function render(): string
    {
        return '<p>' . $this->bind($this->which) . '</p>';
    }
}

/** What the page shows of the form's state, after the browser sets $property to $value. */
function after(string $property, mixed $value): string
{
    return Tab::mount(BindForm::class, [], function (Tab $tab) use ($property, $value) {
        $tab->call('bound', [$property, $value]);

        return preg_replace('#.*<b id="s">(.*?)</b>.*#s', '$1', $tab->html());
    });
}

test('bind() writes the current value, and the event and arguments that set the property', function () {
    $html = Tab::mount(BindForm::class, ['name' => 'Ada "A" <b>', 'age' => 36, 'subscribed' => true, 'plan' => Plan::Pro], fn (Tab $tab) => $tab->html());
    expect($html)
        ->toContain('<input value="Ada &quot;A&quot; &lt;b&gt;" tether-input="bound" tether-args-input="[&quot;name&quot;]">')
        ->toContain('<input value="36" tether-input="bound" tether-args-input="[&quot;age&quot;]">')
        ->toContain('<input type="checkbox" checked tether-change="bound" tether-args-change="[&quot;subscribed&quot;]">')
        ->toContain('<input type="radio" value="free" tether-change="bound"')
        ->toContain('<input type="radio" value="pro" checked tether-change="bound"');
});

test('the value of a field is cast to the type of the property', function () {
    expect(after('name', 'Grace'))->toBe('Grace||0|false|free||')
        ->and(after('name', 12))->toBe('12||0|false|free||')
        ->and(after('age', '42'))->toBe('|42|0|false|free||')
        ->and(after('age', 7))->toBe('|7|0|false|free||')
        ->and(after('age', null))->toBe('||0|false|free||')
        ->and(after('age', ''))->toBe('||0|false|free||')
        ->and(after('price', '1.5'))->toBe('||1.5|false|free||')
        ->and(after('price', 3))->toBe('||3|false|free||')
        ->and(after('subscribed', true))->toBe('||0|true|free||')
        ->and(after('plan', 'pro'))->toBe('||0|false|pro||')
        ->and(after('level', '2'))->toBe('||0|false|free|2|')
        ->and(after('level', ''))->toBe('||0|false|free||')
        ->and(after('tags', ['a', 'b']))->toBe('||0|false|free||a,b');
});

test('a value the property does not take is refused, and the property keeps its value', function (string $property, mixed $value) {
    Tab::mount(BindForm::class, ['name' => 'Ada'], function (Tab $tab) use ($property, $value) {
        expect(fn () => $tab->call('bound', [$property, $value]))->toThrow(InvalidArgumentException::class)
            ->and($tab->html())->toContain('Ada|');
    });
})->with([
    'text for an int'          => ['age', 'abc'],
    'a decimal for an int'     => ['age', '1.5'],
    'a bool for an int'        => ['age', true],
    'empty for a float'        => ['price', ''],
    'infinite float'           => ['price', '1e999'],
    'text for a bool'          => ['subscribed', 'true'],
    'an unknown enum case'     => ['plan', 'gold'],
    'null for a plain enum'    => ['plan', null],
    'a number for a string[]'  => ['tags', [1]],
    'a map for a string[]'     => ['tags', ['a' => 'b']],
    'an array for a string'    => ['name', ['x']],
]);

test('only #[Bind] properties can be set: a plain property, a Component\'s, one that does not exist', function (string $property) {
    Tab::mount(BindForm::class, [], function (Tab $tab) use ($property) {
        expect(fn () => $tab->call('bound', [$property, 'x']))->toThrow(InvalidArgumentException::class);
    });
})->with(['secret', 'tetherId', 'missing', '']);

test('bound() is not callable with other arguments than a property and a value', function () {
    Tab::mount(BindForm::class, [], function (Tab $tab) {
        expect(fn () => $tab->call('bound', ['name']))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $tab->call('bound', ['name', 'a', 'b']))->toThrow(InvalidArgumentException::class);
    });
});

test('bind() of a property that is not a public #[Bind] property of a supported type is an error in render()', function (string $which) {
    expect(fn () => Tab::mount(BindBroken::class, ['which' => $which], fn (Tab $tab) => $tab->html()))->toThrow(LogicException::class);
})->with(['plain', 'union']);
