<?php

use mini\Dispatcher\RequestDispatcher;
use mini\Http\Message\ServerRequest;
use Tether\Circuit;

/**
 * Mount $class live, run $act(circuit, frames) in a coroutine of the tab's request, and return
 * the frames sent and whether the tab crashed. Frames are JSON-decoded, as the browser gets them.
 */
function live(string $class, array $props, Closure $act, array $options = []): array
{
    return phasync::run(function () use ($class, $props, $act, $options) {
        $frames  = new ArrayObject();
        $crashed = null;
        $request = new ServerRequest('GET', '/_tether/live?tab=t1', '');

        return RequestDispatcher::within($request, function () use ($class, $props, $act, $frames, &$crashed, $options) {
            $circuit = new Circuit(...$options + [
                'send'   => static function (array $frame) use ($frames) { $frames[] = json_decode(json_encode($frame), true); },
                'maxFps' => 1000,
                'crash'  => static function (Throwable $e) use (&$crashed) { $crashed = $e->getMessage(); },
            ]);
            $html   = $circuit->mount($class, $props);
            $writer = phasync::go($circuit->run(...));
            try {
                $result = $act($circuit, $frames);
                phasync::sleep(0.02);
            } finally {
                $writer->isTerminated() || phasync::cancel($writer);
                $circuit->close();
            }

            return ['html' => $html, 'frames' => $frames->getArrayCopy(), 'crashed' => $crashed, 'result' => $result ?? null];
        });
    });
}

/** Every reply in $frames, by reply id. */
function replies(array $frames): array
{
    $replies = [];
    foreach ($frames as $frame) {
        foreach ($frame['replies'] ?? [] as $reply) {
            $replies[$reply['r']] = $reply;
        }
    }

    return $replies;
}
