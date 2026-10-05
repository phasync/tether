<?php

namespace Demo;

use Tether\Component;
use Tether\Event\ChangeEventArgs;
use Tether\Event\ClipboardEventArgs;
use Tether\Event\DragEventArgs;
use Tether\Event\EventArgs;
use Tether\Event\FocusEventArgs;
use Tether\Event\IntersectEventArgs;
use Tether\Event\KeyboardEventArgs;
use Tether\Event\PointerEventArgs;
use Tether\Event\ScrollEventArgs;
use Tether\Event\SizeEventArgs;
use Tether\Event\TouchEventArgs;
use Tether\Event\WheelEventArgs;

/**
 * One region per event family. Every handler records how many times it ran and the data it got
 * (#seen, as JSON), and the order the handlers ran in: tests/browser/events.mjs drives it with real input.
 */
final class Events extends Component
{
    /** @var array<string, array{n: int, d: array<string, mixed>}> */
    private array $seen = [];
    /** @var list<string> */
    private array $order = [];

    private function note(string $name, array|EventArgs $data = []): void
    {
        $this->seen[$name] = ['n' => ($this->seen[$name]['n'] ?? 0) + 1, 'd' => $data instanceof EventArgs ? $data->data : $data];
        $this->order[] = $name;
        $this->order = \array_slice($this->order, -40);
    }

    // Pointer, mouse, wheel
    public function padEnter(PointerEventArgs $e): void { $this->note('padEnter', $e); }
    public function padLeave(PointerEventArgs $e): void { $this->note('padLeave', $e); }
    public function padMove(PointerEventArgs $e): void { $this->note('padMove', $e); }
    public function padDown(PointerEventArgs $e): void { $this->note('padDown', $e); }
    public function padWheel(WheelEventArgs $e): void { $this->note('padWheel', $e); }
    public function hoverIn(): void { $this->note('hoverIn'); }
    public function hoverOut(): void { $this->note('hoverOut'); }
    public function intent(): void { $this->note('intent'); }
    public function dragSelect(): void { $this->note('dragSelect'); }

    // Touch
    public function touchStart(TouchEventArgs $e): void { $this->note('touchStart', $e); }
    public function touchMove(TouchEventArgs $e): void { $this->note('touchMove', $e); }
    public function touchEnd(TouchEventArgs $e): void { $this->note('touchEnd', $e); }

    // Keyboard
    public function enter(KeyboardEventArgs $e): void { $this->note('enter', $e); }
    public function ctrlK(): void { $this->note('ctrlK'); }
    public function shiftA(KeyboardEventArgs $e): void { $this->note('shiftA', $e); }
    public function once(KeyboardEventArgs $e): void { $this->note('once', $e); }
    public function slash(): void { $this->note('slash'); }
    public function escape(): void { $this->note('escape'); }

    // Focus
    public function focused(FocusEventArgs $e): void { $this->note('focused', $e); }
    public function blurred(FocusEventArgs $e): void { $this->note('blurred', $e); }
    public function focusIn(FocusEventArgs $e): void { $this->note('focusIn', $e); }
    public function focusOut(FocusEventArgs $e): void { $this->note('focusOut', $e); }

    // Click variants
    public function outside(): void { $this->note('outside'); }
    public function outer(): void { $this->note('outer'); }
    public function inner(): void { $this->note('inner'); }
    public function multi(int $n): void { $this->note('multi', ['n' => $n]); }
    public function whitelisted(EventArgs $e): void { $this->note('whitelisted', $e); }
    public function ping(): void { $this->note('ping'); }
    public function serial(): void { \phasync::sleep(0.15); $this->note('serial'); }
    public function dropped(): void { \phasync::sleep(0.15); $this->note('dropped'); }

    // Typing: latest (the default), debounce, composition, the form
    public function search(string $value): void { \phasync::sleep(0.15); $this->note('search', ['value' => $value]); }
    public function debounced(string $value): void { $this->note('debounced', ['value' => $value]); }
    public function composed(string $value, ChangeEventArgs $e): void { $this->note('composed', $e); }
    public function formInput(array $fields, string $name): void { $this->note('formInput', ['fields' => $fields, 'name' => $name]); }
    public function formChange(array $fields, string $name): void { $this->note('formChange', ['fields' => $fields, 'name' => $name]); }

    // Window, document, observers, scroll
    public function resized(SizeEventArgs $e): void { $this->note('resized', $e); }
    public function boxResized(SizeEventArgs $e): void { $this->note('boxResized', $e); }
    public function visible(EventArgs $e): void { $this->note("visible", $e); }
    public function offline(): void { $this->note('offline'); }
    public function online(): void { $this->note('online'); }
    public function scrolled(ScrollEventArgs $e): void { $this->note('scrolled', $e); }
    public function seenBy(IntersectEventArgs $e): void { $this->note('seenBy', $e); }

    // Drag and drop, clipboard
    public function dragStarted(DragEventArgs $e): void { $this->note('dragStarted', $e); }
    public function dragEnded(DragEventArgs $e): void { $this->note('dragEnded', $e); }
    public function dragOver(DragEventArgs $e): void { $this->note('dragOver', $e); }
    public function dropOn(DragEventArgs $e): void { $this->note('dropOn', $e); }
    public function pasted(ClipboardEventArgs $e): void { $this->note('pasted', $e); }

    public function render(): string
    {
        $seen = \htmlspecialchars(\json_encode(['seen' => $this->seen, 'order' => $this->order], JSON_THROW_ON_ERROR));

        return <<<HTML
            <div id="events" tether-on-keydown.document.key-slash.nofield="slash" tether-on-visibilitychange="visible" tether-on-online="online" tether-on-offline="offline" tether-on-resize.throttle-50="resized">
              <pre id="seen">{$seen}</pre>

              <div id="pad" tether-on-pointerenter="padEnter" tether-on-pointerleave="padLeave" tether-on-pointermove="padMove" tether-on-pointerdown.mouse.left="padDown" tether-on-wheel.prevent="padWheel">pad</div>
              <div id="hover" tether-on-mouseenter="hoverIn" tether-on-mouseleave="hoverOut">hover <span id="hoverchild">child</span></div>
              <div id="intent" tether-on-mouseover.delay-300="intent">intent</div>
              <div id="held" tether-on-mousemove.held="dragSelect">held</div>
              <div id="touch" tether-on-touchstart.prevent="touchStart" tether-on-touchmove="touchMove" tether-on-touchend="touchEnd">touch</div>

              <input id="keys" tether-keydown.key-enter="enter" tether-on-keydown.ctrl.key-k.prevent="ctrlK" tether-on-keydown.code-keya.shift="shiftA" tether-on-keydown.key-x.norepeat="once" tether-on-keyup.key-escape="escape">
              <div id="focuswrap" tether-on-focusin="focusIn" tether-on-focusout="focusOut">
                <input id="name" name="name" tether-on-focus="focused" tether-on-blur="blurred">
                <input id="other" name="other">
              </div>

              <div id="menu" tether-on-click.outside="outside">menu</div>
              <div id="outer" tether-click="outer"><div id="stopper" tether-on-click.stop="">stopper</div><div id="innerbtn" tether-click="inner">inner</div></div>
              <button id="multi" tether-args="[1]" tether-args-click="[2]" tether-click="multi" tether-on-dblclick="multi">multi</button>
              <button id="wl" tether-click="whitelisted" tether-event="shiftKey target.id currentTarget.id">whitelist</button>
              <button id="ping" tether-click="ping">ping</button>
              <button id="serial" tether-on-click.serial="serial">serial</button>
              <button id="drop" tether-on-click.drop="dropped">drop</button>

              <input id="q" tether-input="search">
              <input id="deb" tether-on-input.debounce-300="debounced">
              <input id="ime" tether-input="composed">
              <form id="form" tether-input="formInput" tether-change="formChange">
                <input name="a" value="x">
                <input name="n" type="number" value="1">
                <input name="r" type="radio" value="p"><input name="r" type="radio" value="q">
              </form>

              <div id="box" tether-on-elementresize="boxResized" style="width: 100px; height: 40px">box</div>
              <div id="scroller" tether-on-scroll="scrolled" style="height: 100px; overflow: auto">
                <div style="height: 300px">spacer</div>
                <div id="seeme" tether-on-intersect.threshold-50="seenBy" style="height: 40px">seeme</div>
                <div style="height: 300px">spacer</div>
              </div>

              <div id="drag" draggable="true" tether-on-dragstart="dragStarted" tether-on-dragend="dragEnded">drag</div>
              <div id="target" tether-on-dragover.prevent="dragOver" tether-on-drop="dropOn">target</div>
              <textarea id="paste" tether-on-paste="pasted"></textarea>
            </div>
            HTML;
    }
}
