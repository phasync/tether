<?php

namespace Showcase;

use Tether\Event\PointerEventArgs;
use Tether\Event\TouchEventArgs;

final class TouchPad extends Section
{
    public const ID    = 'touch';
    public const TITLE = 'Touch pad';
    protected const BLURB = 'Put one or more fingers on the pad: touchstart, touchmove and touchend carry every touch point. With a mouse, pointer events stand in for a single finger.';
    protected const SHOW  = ['touches', 'mouse', 'track', 'demo'];

    /** @var array<int|string, array{0: float, 1: float}> */
    private array $points = [];

    private int $most = 0;

    private int $events = 0;

    private string $last = '';

    public function touches(TouchEventArgs $e): void
    {
        $this->track(\array_map(static fn (array $t) => [$t['clientX'], $t['clientY']], \array_column($e->touches, null, 'identifier')), $e->type);
    }

    public function mouse(PointerEventArgs $e): void
    {
        $this->track('pointerdown' === $e->type || 'pointermove' === $e->type ? ['mouse' => [$e->clientX, $e->clientY]] : [], $e->type);
    }

    /** @param array<int|string, array{0: float, 1: float}> $points */
    private function track(array $points, string $type): void
    {
        $this->points = $points;
        $this->most   = \max($this->most, \count($points));
        $this->last   = $type;
        ++$this->events;
    }

    protected function demo(): string
    {
        $fingers = '';
        foreach ($this->points as $id => [$x, $y]) {
            $fingers .= "<i class=\"finger\" style=\"left: {$x}px; top: {$y}px\">" . (\is_int($id) ? $id : '') . '</i>';
        }
        $now = \count($this->points);

        return <<<HTML
            <div class="pad" id="pad"
                 tether-on-touchstart="touches" tether-on-touchmove="touches" tether-on-touchend="touches" tether-on-touchcancel="touches"
                 tether-on-pointerdown.mouse.left="mouse" tether-on-pointermove.mouse.held="mouse" tether-on-pointerup.mouse="mouse" tether-on-pointerleave.mouse="mouse">
              <span class="hint">Touch or drag here</span>{$fingers}
            </div>
            <dl class="stats">
              <div><dt>touching</dt><dd id="touching">{$now}</dd></div>
              <div><dt>most at once</dt><dd id="most">{$this->most}</dd></div>
              <div><dt>events</dt><dd id="touch-events">{$this->events}</dd></div>
              <div><dt>last</dt><dd id="touch-last">{$this->last}</dd></div>
            </dl>
            HTML;
    }
}
