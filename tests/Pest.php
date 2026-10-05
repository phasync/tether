<?php

use mini\Dispatcher\RequestDispatcher;
use mini\Http\Message\ServerRequest;
use Tether\Circuit;

/**
 * Mount $class live, run $act(circuit, frames) in a coroutine of the tab's request, and return
 * the frames sent and whether the tab crashed. Frames are JSON-decoded, as the browser gets them.
 *
 * $browser, when given, is called in a coroutine of its own for each op frame the server sends,
 * and answers with $circuit->returned(): a scripted browser.
 *
 * @param (Closure(array, Circuit): void)|null $browser
 */
function live(string $class, array $props, Closure $act, array $options = [], ?Closure $browser = null): array
{
    return phasync::run(function () use ($class, $props, $act, $options, $browser) {
        $frames  = new ArrayObject();
        $crashed = null;
        $request = new ServerRequest('GET', '/_tether/live?tab=t1', '');

        return RequestDispatcher::within($request, function () use ($class, $props, $act, $frames, &$crashed, $options, $browser) {
            $circuit = null;
            $circuit = new Circuit(...$options + [
                'send'   => static function (array $frame) use ($frames, $browser, &$circuit) {
                    $frames[] = $frame = json_decode(json_encode($frame), true);
                    if (null !== $browser && 'op' === $frame['t']) {
                        phasync::go(static fn () => $browser($frame, $circuit));
                    }
                },
                'maxFps' => 1000,
                'crash'  => static function (Throwable $e) use (&$crashed) { $crashed = $e->getMessage(); },
            ]);
            $html   = $circuit->mount($class, $props);
            $circuit->delivered();
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
