<?php

namespace Tether;

use phasync\CancelledException;

/**
 * A Promise in the browser. It is not awaited by itself: start several, then wait for them,
 * and they run at once.
 *
 * ```php
 * $a = $browser->call('fetch', '/a');
 * $b = $browser->call('fetch', '/b');
 * $responseA = \phasync::await($a, 5.0);   // a JsObject, or a JsException when it rejects
 * $responseB = \phasync::await($b, 5.0);
 * ```
 *
 * `phasync::await()` takes it as any promise: what it resolves to follows the rules of
 * {@see Browser}. The browser does not wait longer for the answer than Limits::$callTimeout (or
 * the Browser::within() of the coroutine that called `then()`); a `phasync::await()` timeout
 * stops the server waiting, not the Promise.
 */
class JsPromise extends JsObject
{
    /**
     * @param ?callable(mixed): mixed          $onFulfilled
     * @param ?callable(\Throwable): mixed       $onRejected
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): void
    {
        $browser = $this->browser;
        $browser->start(function () use ($browser, $onFulfilled, $onRejected) {
            try {
                $value = $browser->remote()->op($browser, 'await', ['h' => $this->id]);
            } catch (JsException|CancelledException $e) {
                // The tab closing too: whatever awaits the Promise must not wait for ever
                null === $onRejected || $onRejected($e);
                if ($e instanceof CancelledException) {
                    throw $e;
                }

                return;
            }
            null === $onFulfilled || $onFulfilled($value);
        });
    }
}
