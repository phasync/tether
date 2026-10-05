<?php

namespace Showcase;

use Tether\Component;

/** The page: ten components, each bound to the browser with nothing but attributes and public methods. */
final class Showcase extends Component
{
    private const SECTIONS = [Pointer::class, TouchPad::class, Keys::class, SignUp::class, Reorder::class, Viewport::class, Interop::class, Draw::class, Objects::class, Widget::class];

    public function render(): string
    {
        $nav = $cards = '';
        foreach (self::SECTIONS as $class) {
            $nav   .= '<a href="#' . $class::ID . '">' . $this->e($class::TITLE) . '</a>';
            $cards .= $this->child($class);
        }

        return <<<HTML
            <div class="page">
              <header class="top">
                <h1>Tether</h1>
                <p>Components that live on the server and bind to the browser with attributes. Every card below is one component: the handler source next to the result is read from the files that run it.</p>
                <p class="status"><span class="dot"></span><span class="off">connecting</span><span class="on">live</span></p>
                <nav>{$nav}</nav>
              </header>
              <main>{$cards}</main>
              <footer class="foot">Source: playground/showcase</footer>
            </div>
            HTML;
    }
}
