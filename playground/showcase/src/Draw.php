<?php

namespace Showcase;

use Tether\Event\PointerEventArgs;
use Tether\JsObject;

final class Draw extends Section
{
    public const ID    = 'canvas';
    public const TITLE = 'Drawing on a canvas';
    protected const BLURB = 'The server draws: each pointer event is a few calls on the canvas context, which is a PHP object standing for the browser\'s. Every call is a round trip, so this style is for demos and small drawings, not for per-frame loops: those belong in a hook.';
    protected const SHOW  = ['down', 'move', 'up', 'clear', 'context'];

    private const COLOURS = ['#4f6df5', '#e0457b', '#12a594', '#d98e04'];

    private const WIDTH = 480;

    private const HEIGHT = 240;

    private string $colour = '#4f6df5';

    /** @var array{0: float, 1: float}|null */
    private ?array $last = null;

    private ?JsObject $ctx = null;

    public function down(PointerEventArgs $e): void
    {
        $this->last = $this->at($e);
        $ctx        = $this->context();
        $ctx->fillStyle = $this->colour;
        $ctx->beginPath();
        $ctx->arc($this->last[0], $this->last[1], 3, 0, 2 * \M_PI);
        $ctx->fill();
    }

    public function move(PointerEventArgs $e): void
    {
        $to   = $this->at($e);
        $from = $this->last ?? $to;
        $this->last = $to;
        $ctx  = $this->context();
        $ctx->strokeStyle = $this->colour;
        $ctx->beginPath();
        $ctx->moveTo(...$from);
        $ctx->lineTo(...$to);
        $ctx->stroke();
    }

    public function up(): void
    {
        $this->last = null;
    }

    public function clear(): void
    {
        $this->context()->clearRect(0, 0, self::WIDTH, self::HEIGHT);
    }

    public function pick(string $colour): void
    {
        $this->colour = $colour;
    }

    private function context(): JsObject
    {
        if (null === $this->ctx) {
            $this->ctx = $this->browser()->ref('board')->getContext('2d');
            $this->ctx->lineWidth = 6;
            $this->ctx->lineCap   = 'round';
        }

        return $this->ctx;
    }

    /** @return array{0: float, 1: float} */
    private function at(PointerEventArgs $e): array
    {
        return [$e->offsetX * self::WIDTH / $e->data['target.clientWidth'], $e->offsetY * self::HEIGHT / $e->data['target.clientHeight']];
    }

    protected function demo(): string
    {
        $swatches = '';
        foreach (self::COLOURS as $colour) {
            $swatches .= '<button type="button" class="swatch' . ($this->colour === $colour ? ' on' : '') . "\" style=\"background: {$colour}\" aria-label=\"{$colour}\" tether-click=\"pick\" tether-args='[\"{$colour}\"]'></button>";
        }
        [$w, $h] = [self::WIDTH, self::HEIGHT];

        return <<<HTML
            <canvas class="board" id="board" tether-ref="board" tether-ignore width="{$w}" height="{$h}"
                    tether-event="target.clientWidth target.clientHeight"
                    tether-on-pointerdown.left="down" tether-on-pointermove.held.drop="move" tether-on-pointerup="up" tether-on-pointerleave="up"></canvas>
            <p class="row" id="swatches-draw">{$swatches} <button id="clear" tether-click="clear">Clear</button></p>
            HTML;
    }
}
