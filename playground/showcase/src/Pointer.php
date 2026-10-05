<?php

namespace Showcase;

use Swerve\Swerve;
use Tether\Event\PointerEventArgs;

final class Pointer extends Section
{
    public const ID    = 'pointer';
    public const TITLE = 'Pointer and hover';
    protected const BLURB = 'pointermove, throttled to 50 ms, places the marker; open the page in two tabs and each shows the other\'s pointer through publish/subscribe. The chip opens its card only when the pointer stays 300 ms: hover intent.';
    protected const SHOW  = ['move', 'left', 'showCard', 'hideCard', 'run', 'demo'];

    private string $me = '';

    /** @var array{0: float, 1: float}|null */
    private ?array $mine = null;

    private bool $card = false;

    /** @var array<string, array{0: float, 1: float, 2: float}> tab id => x, y, seen at */
    private array $others = [];

    public function mount(): void
    {
        $this->me = \bin2hex(\random_bytes(3));
    }

    public function move(PointerEventArgs $e): void
    {
        $this->mine = [\round($e->offsetX / $e->data['target.clientWidth'], 3), \round($e->offsetY / $e->data['target.clientHeight'], 3)];
        Swerve::publish('showcase:pointer', [$this->me, ...$this->mine]);
    }

    public function left(): void
    {
        $this->mine = null;
        Swerve::publish('showcase:pointer', [$this->me, null, null]);
    }

    public function showCard(): void
    {
        $this->card = true;
    }

    public function hideCard(): void
    {
        $this->card = false;
    }

    public function run(): void
    {
        foreach (Swerve::subscribe('showcase:pointer') as [$id, $x, $y]) {
            if ($id === $this->me) {
                continue;
            }
            if (null === $x) {
                unset($this->others[$id]);
            } else {
                $this->others[$id] = [$x, $y, \microtime(true)];
            }
            $this->others = \array_filter($this->others, static fn (array $o) => $o[2] > \microtime(true) - 10);
            $this->requestRender();
        }
    }

    public function dispose(): void
    {
        Swerve::publish('showcase:pointer', [$this->me, null, null]);
    }

    protected function demo(): string
    {
        $markers = '';
        if (null !== $this->mine) {
            $markers .= $this->marker($this->me, $this->mine, 'me');
        }
        $cards = '';
        foreach ($this->others as $id => [$x, $y]) {
            $markers .= $this->marker($id, [$x, $y], 'other');
            $cards   .= '<li><b style="color: hsl(' . $this->hue($id) . ' 70% 50%)">' . $this->e($id) . '</b> at ' . \round($x * 100) . '%, ' . \round($y * 100) . '%</li>';
        }
        $count = \count($this->others);
        $card  = $this->card ? "<span class=\"hovercard\" id=\"hovercard\"><b>This tab: {$this->me}</b><ul>{$cards}</ul></span>" : '';

        return <<<HTML
            <div class="field" id="field" tether-on-pointermove.throttle-50="move" tether-on-pointerleave="left" tether-event="target.clientWidth target.clientHeight">
              {$markers}
              <span class="hint">Move a pointer here</span>
            </div>
            <p class="row">
              <span class="chip" id="chip" tether-on-mouseenter.delay-300="showCard" tether-on-mouseleave="hideCard">{$count} other tabs: hover for a card{$card}</span>
            </p>
            HTML;
    }

    /** @param array{0: float, 1: float} $at */
    private function marker(string $id, array $at, string $kind): string
    {
        return '<i class="marker ' . $kind . '" style="left: ' . $at[0] * 100 . '%; top: ' . $at[1] * 100 . '%; --hue: ' . $this->hue($id) . '"></i>';
    }

    private function hue(string $id): int
    {
        return \crc32($id) % 360;
    }
}
