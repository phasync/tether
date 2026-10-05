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
 */
final readonly class Limits
{
    /**
     * @param int $eventsPerSecond tokens added to the bucket each second
     * @param int $burst           the bucket's size
     * @param int $running         event handlers running or waiting to run in a tab, at most
     * @param int $bytes           the largest message the client sends (it drops larger ones)
     */
    public function __construct(
        public int $eventsPerSecond = 200,
        public int $burst = 400,
        public int $running = 64,
        public int $bytes = 524288,
    ) {
        if ($eventsPerSecond < 1 || $burst < 1 || $running < 1 || $bytes < 1) {
            throw new \InvalidArgumentException('Every Limits value must be at least 1');
        }
    }
}
