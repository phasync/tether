<?php

namespace Showcase;

use Tether\Event\EventArgs;
use Tether\Event\IntersectEventArgs;
use Tether\Event\ScrollEventArgs;
use Tether\Event\SizeEventArgs;

final class Viewport extends Section
{
    public const ID    = 'viewport';
    public const TITLE = 'Window, scroll and visibility';
    protected const BLURB = 'resize, scroll, visibilitychange, online and offline are bound on the window and document from this card; elementresize and intersect watch one element. run() reads the starting values from the browser.';
    protected const SHOW  = ['run', 'resized', 'scrolled', 'visibility', 'network', 'boxResized', 'lazy', 'demo'];

    /** @var array{0: int, 1: int, 2: float}|null */
    private ?array $size = null;

    private int $scrollY = 0;

    private bool $hidden = false;

    private int $switches = 0;

    private bool $online = true;

    /** @var array{0: int, 1: int}|null */
    private ?array $box = null;

    private string $lazy = '';

    public function run(): void
    {
        $window       = $this->browser()->window;
        $this->size   = [$window->innerWidth, $window->innerHeight, $window->devicePixelRatio];
        $this->online = $window->navigator->onLine;
        $this->requestRender();
    }

    public function resized(SizeEventArgs $e): void
    {
        $this->size = [(int) $e->innerWidth, (int) $e->innerHeight, $e->devicePixelRatio];
    }

    public function scrolled(ScrollEventArgs $e): void
    {
        // Elements that scroll (a code block) reach a window binding too, without scrollY
        $this->scrollY = (int) ($e->data['scrollY'] ?? $this->scrollY);
    }

    public function visibility(EventArgs $e): void
    {
        $this->hidden = $e->data['hidden'];
        ++$this->switches;
    }

    public function network(EventArgs $e): void
    {
        $this->online = 'online' === $e->type;
    }

    public function boxResized(SizeEventArgs $e): void
    {
        $this->box = [(int) $e->width, (int) $e->height];
    }

    public function lazy(IntersectEventArgs $e): void
    {
        if ($e->isIntersecting && '' === $this->lazy) {
            $this->lazy = 'loading';
            $this->requestRender();
            \phasync::sleep(0.4);
            $this->lazy = 'loaded';
        }
    }

    protected function demo(): string
    {
        $size    = null === $this->size ? 'unknown' : "{$this->size[0]} x {$this->size[1]} at {$this->size[2]}x";
        $box     = null === $this->box ? 'unknown' : "{$this->box[0]} x {$this->box[1]}";
        $visible = $this->hidden ? 'hidden' : 'visible';
        $network = $this->online ? 'online' : 'offline';
        $lazy    = match ($this->lazy) {
            'loaded'  => '<p class="ok" id="lazy-text">Loaded when it came into view.</p>',
            'loading' => '<p id="lazy-text">Loading...</p>',
            default   => '<p id="lazy-text" class="muted">Scroll down to load this.</p>',
        };

        return <<<HTML
            <div tether-on-resize="resized" tether-on-scroll.window="scrolled" tether-on-visibilitychange="visibility" tether-on-online="network" tether-on-offline="network">
              <dl class="stats">
                <div><dt>window</dt><dd id="vp-size">{$size}</dd></div>
                <div><dt>scrolled</dt><dd id="vp-scroll">{$this->scrollY} px</dd></div>
                <div><dt>document</dt><dd id="vp-visible">{$visible}, changed {$this->switches}x</dd></div>
                <div><dt>network</dt><dd id="vp-network">{$network}</dd></div>
              </dl>
              <div class="resizable" id="resizable" tether-on-elementresize="boxResized" tether-ignore>Drag my corner to resize me</div>
              <p class="row">The box is <b id="vp-box">{$box}</b></p>
              <div class="lazy" id="lazy" tether-on-intersect.threshold-20="lazy">{$lazy}</div>
            </div>
            HTML;
    }
}
