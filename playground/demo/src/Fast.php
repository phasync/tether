<?php

namespace Demo;

use Tether\Component;

/** Updates $rate times a second: a frame counter, the rate it achieves, and a moving bar. */
final class Fast extends Component
{
    public int $rate = 50;

    public int $frame = 0;

    public float $achieved = 0.0;

    public float $position = 0.0;

    public function run(): void
    {
        $start = hrtime(true);
        while (true) {
            ++$this->frame;
            $elapsed        = (hrtime(true) - $start) / 1e9;
            $this->achieved = $elapsed > 0 ? $this->frame / $elapsed : 0;
            $this->position = (sin($elapsed * 2) + 1) / 2;
            $this->update();
            \phasync::sleep(1 / $this->rate);
        }
    }

    public function render(): string
    {
        $width    = round($this->position * 100, 1);
        $achieved = number_format($this->achieved, 1);

        return <<<HTML
            <section>
              <p>Frame {$this->frame}, {$achieved} updates/s (asked {$this->rate})</p>
              <div style="height: 1.5rem; background: #eee; border-radius: 4px; overflow: hidden">
                <div style="height: 100%; width: {$width}%; background: #4a7"></div>
              </div>
            </section>
            HTML;
    }
}
