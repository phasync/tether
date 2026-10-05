<?php

namespace Tether\Testing;

use phasync\Psr\ServerRequest;
use phasync\Psr\StringStream;
use Psr\Http\Message\ServerRequestInterface;
use Tether\Circuit;
use Tether\JsException;
use Tether\Limits;
use Tether\Page;
use Tether\Tether;

/**
 * A live tab without a browser, for tests: the page's components are mounted and run in the
 * coroutines of a phasync::run(), and what the browser would do is a method call.
 *
 * ```php
 * Tab::mount(Counter::class, ['start' => 3], function (Tab $tab) {
 *     $tab->call('increment', [4]);
 *     expect($tab->html())->toContain('7');
 * });
 * ```
 *
 * Events go through the same checks as the browser's (the handler must be a public method of
 * the component's class, with arguments of its parameter types), and frames are what the
 * writer would send. html() is the browser's DOM: the mount HTML with every patch applied, so
 * what a component changed without requestRender() is not in it.
 */
final class Tab
{
    /** What failed the tab (everything unmounted, the connection would close), if it did. */
    public ?\Throwable $crashed = null;

    /** @var list<array> the frames sent to the browser, as JSON-decoded as it gets them */
    public array $frames = [];

    private readonly Circuit $circuit;

    private ?string $dom;

    private int $replies = 0;

    /**
     * Mount $class with $props and run $test with the tab; the tab is closed after, and what
     * $test returns is returned.
     *
     * @param class-string<\Tether\Component>        $class
     * @param \Closure(Tab): mixed                   $test
     * @param (\Closure(array): mixed)|null          $browser answers the server's calls into the browser: gets the op
     *                                                        frame and returns the value, or throws a JsException; without it
     *                                                        a call waits for its timeout
     * @param (\Closure(ServerRequestInterface, \Closure): void)|null $enter as for Tether::from()
     */
    public static function mount(string $class, array $props, \Closure $test, ?\Closure $browser = null, ?ServerRequestInterface $request = null, ?\Closure $enter = null, Limits $limits = new Limits()): mixed
    {
        return self::start(new Page($class, $props), $test, $browser, $request, $enter, $limits);
    }

    /**
     * As mount(), for the closure of a Tether::from() page: it runs with `$t->live` true, as for a
     * live connection, and must return a Page.
     *
     * @param \Closure(Tether): Page $page
     * @param \Closure(Tab): mixed   $test
     */
    public static function page(\Closure $page, \Closure $test, ?\Closure $browser = null, ?ServerRequestInterface $request = null, ?\Closure $enter = null, Limits $limits = new Limits()): mixed
    {
        $result = $page(new Tether(live: true));
        if (!$result instanceof Page) {
            throw new \LogicException('The page closure must return a Page, not ' . \get_debug_type($result));
        }

        return self::start($result, $test, $browser, $request, $enter, $limits);
    }

    /**
     * Send an event to component $id (the root is c1), as the browser does, and wait until the
     * tab is idle. $payload is what the browser says about the event (an EventArgs parameter).
     *
     * @throws \InvalidArgumentException the browser's call would be refused
     * @throws \LogicException            no such component in the page (the browser's event would be ignored)
     */
    public function call(string $method, array $args = [], string $id = 'c1', array $payload = []): void
    {
        $this->extent($id);
        $this->circuit->event($id, $method, $args, payload: $payload);
        $this->circuit->idle();
    }

    /**
     * As call(), for an #[Invokable] handler: what Tether.invoke() gives the browser. A handler
     * that failed in a way the tab survived (an error boundary caught it) throws a
     * RuntimeException; one that crashed the tab throws what crashed it.
     */
    public function invoke(string $method, array $args = [], string $id = 'c1', array $payload = []): mixed
    {
        $this->extent($id);
        $reply = ++$this->replies;
        $this->circuit->event($id, $method, $args, $reply, $payload, true);
        $this->circuit->idle();
        foreach ($this->frames as $frame) {
            foreach ($frame['replies'] ?? [] as $r) {
                if ($reply === $r['r']) {
                    return isset($r['e']) ? throw new \RuntimeException($r['e']) : $r['v'];
                }
            }
        }

        throw $this->crashed ?? new \LogicException('The handler gave no reply');
    }

    /** Let the tab's coroutines run for $seconds (run() loops, timers), then wait until it is idle. */
    public function advance(float $seconds): void
    {
        \phasync::sleep($seconds);
        $this->circuit->idle();
    }

    /** Wait until the tab is idle: nothing running, nothing left to send. */
    public function idle(): void
    {
        $this->circuit->idle();
    }

    /**
     * The HTML the browser shows: the whole page, or component $id's element. Throws what crashed the tab.
     * Components are found in it by their tether-id, so the HTML must be well formed.
     */
    public function html(?string $id = null): string
    {
        if (null !== $this->crashed) {
            throw $this->crashed;
        }

        return null === $id ? $this->dom : \substr($this->dom, ...$this->extent($id));
    }

    private function __construct(Page $page, ?\Closure $browser, ServerRequestInterface $request, Limits $limits)
    {
        $this->circuit = new Circuit(
            send: function (array $frame) use ($browser) {
                $this->frames[] = $frame = \json_decode(\json_encode($frame), true);
                foreach ($frame['patches'] ?? [] as $patch) {
                    [$start, $length] = $this->extent($patch['id']);
                    $this->dom = \substr_replace($this->dom, $patch['html'], $start, $length);
                }
                if ('op' === $frame['t'] && null !== $browser) {
                    \phasync::go(fn () => $this->answer($frame, $browser));
                }
            },
            maxFps: 1000,
            crash: fn (\Throwable $e) => $this->crashed = $e,
            request: $request,
            limits: $limits,
            abuse: static fn () => throw new \LogicException('The test sent more events than the Limits allow: give Tab a larger Limits'),
        );
        $this->dom = $this->circuit->mount($page->class, $page->props);
        $this->circuit->delivered();
    }

    private static function start(Page $page, \Closure $test, ?\Closure $browser, ?ServerRequestInterface $request, ?\Closure $enter, Limits $limits): mixed
    {
        $request ??= new ServerRequest('GET', '/', new StringStream(''), ['Host' => 'localhost']);

        return \phasync::run(static function () use ($page, $test, $browser, $request, $enter, $limits) {
            $result = null;
            $run    = static function () use ($page, $test, $browser, $request, $limits, &$result) {
                $tab    = new self($page, $browser, $request, $limits);
                $writer = \phasync::go($tab->circuit->run(...));
                try {
                    $result = $test($tab);
                } finally {
                    $writer->isTerminated() || \phasync::cancel($writer);
                    $tab->circuit->close();
                }
            };
            null === $enter ? $run() : $enter($request, $run);

            return $result;
        });
    }

    private function answer(array $op, \Closure $browser): void
    {
        $error = null;
        try {
            $value = $browser($op);
        } catch (JsException $e) {
            [$value, $error] = [null, ['n' => $e->jsName, 'm' => $e->getMessage(), 's' => $e->jsStack]];
        }
        $this->circuit->returned($op['i'], $value, $error);
    }

    /** @return array{0: int, 1: int} offset and length of component $id's element in the DOM */
    private function extent(string $id): array
    {
        $at = \strpos($this->dom, 'tether-id="' . $id . '"');
        if (false === $at) {
            throw new \LogicException("No component $id in the page");
        }
        $start = \strrpos(\substr($this->dom, 0, $at), '<');
        \preg_match('/<([a-zA-Z][a-zA-Z0-9-]*)/A', $this->dom, $name, 0, $start);
        \preg_match_all('#<(/?)' . $name[1] . '(?=[\s/>])[^>]*>#', $this->dom, $tags, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE, $start);
        $depth = 0;
        foreach ($tags as $tag) {
            $depth += '' === $tag[1][0] ? 1 : -1;
            if (0 === $depth) {
                return [$start, $tag[0][1] + \strlen($tag[0][0]) - $start];
            }
        }

        throw new \LogicException("The element of component $id is not closed");
    }
}
