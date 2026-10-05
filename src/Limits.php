<?php

namespace Tether;

/**
 * What a tab may ask of the server, and what the browser client holds itself to (it gets the
 * numbers in the mount frame and keeps to 90% of the rate, so an honest tab never reaches them).
 *
 * Events, navigations and the rest of what the browser sends draw from one token bucket that
 * refills at $eventsPerSecond up to $burst. An empty bucket is abuse: the connection closes
 * with 4429. More than $running handlers running at once in a tab is an overrun: that event is
 * refused (with an error reply when the browser waits for one), and the connection stays.
 *
 * The server's calls into the browser are limited too: $calls outstanding at once, $handles
 * objects held, and each call waits $callTimeout seconds for its answer unless
 * Browser::within() says otherwise.
 *
 * A tab that has sent nothing for $clientTimeout seconds is dead (a half-open connection): the
 * server closes it with 4408. The client sends a heartbeat when it is quiet for half of that
 * (at most 25 s), and reconnects when it has heard nothing at all for twice that.
 */
final readonly class Limits
{
    /**
     * @param int   $eventsPerSecond tokens added to the bucket each second
     * @param int   $burst           the bucket's size
     * @param int   $running         event handlers running or waiting to run in a tab, at most
     * @param int   $bytes           the largest message the client sends (it drops larger ones)
     * @param int   $calls           calls into the browser outstanding in a tab, at most
     * @param int   $handles         browser objects a tab holds as JsObjects, at most
     * @param float $callTimeout     seconds a call into the browser waits for its answer
     * @param float $clientTimeout   seconds of silence from the browser after which its connection is closed
     */
    public function __construct(
        public int $eventsPerSecond = 200,
        public int $burst = 400,
        public int $running = 64,
        public int $bytes = 524288,
        public int $calls = 32,
        public int $handles = 4096,
        public float $callTimeout = 10.0,
        public float $clientTimeout = 60.0,
    ) {
        if ($eventsPerSecond < 1 || $burst < 1 || $running < 1 || $bytes < 1 || $calls < 1 || $handles < 1 || $callTimeout <= 0 || $clientTimeout <= 0) {
            throw new \InvalidArgumentException('Every Limits value must be at least 1 (callTimeout and clientTimeout above 0)');
        }
    }
}
