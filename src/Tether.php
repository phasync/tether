<?php

namespace Tether;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Http\Message\Response;
use Swerve\Swerve;
use Tether\Transport\WebSocket;

/**
 * Tether's entry points.
 *
 * - page(): a page whose root is a component, as a response: its HTML rendered once, and the
 *   browser client, which then connects and mounts it live.
 * - As PSR-15 middleware: serves the client (/_tether/tether.js, /_tether/idiomorph.js) and the
 *   live connection (/_tether/live); everything else goes to the application.
 *
 * The root's props travel through the browser to the live connection, which may reach another
 * worker: they are signed, with TETHER_SECRET, or else a key kept in the system's temporary
 * directory.
 *
 * The live connection is the tab's request (the WebSocket upgrade, with the tab's cookies),
 * for as long as the tab is open: every component coroutine runs in its phasync context.
 * $enter lets the framework make it the current request for them: with mini,
 * `new Tether(enter: RequestDispatcher::within(...))`. A browser page from another site can
 * not open one (its Origin must be this host, or one of $origins).
 */
final class Tether implements MiddlewareInterface
{
    private const ASSETS = [
        '/_tether/tether.js'    => 'tether.js',
        '/_tether/idiomorph.js' => 'idiomorph.min.js',
    ];

    /**
     * @param list<string>                                                 $origins other origins whose pages may open live
     *                                                                              connections, such as "https://app.example.com"
     * @param \Closure(ServerRequestInterface, \Closure(): void): void|null $enter   runs a tab (the closure) as the work of its
     *                                                                              request, after the framework handled it
     */
    public function __construct(private readonly array $origins = [], private readonly ?\Closure $enter = null)
    {
    }

    /**
     * @param class-string<Component> $class
     * @param array<string, mixed>    $props  JSON-encodable
     * @param string                  $title  text
     * @param string                  $head   HTML for the head: the application's styles, and
     *                                        its scripts (defer, to run after Tether's: hooks)
     */
    public static function page(string $class, array $props = [], string $title = '', string $head = ''): ResponseInterface
    {
        $html  = (new Circuit())->mount($class, $props);
        $title = \htmlspecialchars($title);
        $mount = \json_encode(['c' => $class, 'p' => $props, 's' => self::sign($class, $props)], \JSON_THROW_ON_ERROR | \JSON_HEX_TAG | \JSON_HEX_AMP);

        return new Response(<<<HTML
            <!doctype html>
            <html>
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$title}</title>
            <script src="/_tether/idiomorph.js" defer></script>
            <script src="/_tether/tether.js" defer></script>
            {$head}
            </head>
            <body>
            {$html}
            <script type="application/json" id="tether-mount">{$mount}</script>
            </body>
            </html>
            HTML, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (isset(self::ASSETS[$path])) {
            return new Response(\fopen(\dirname(__DIR__) . '/resources/' . self::ASSETS[$path], 'r'), ['Content-Type' => 'text/javascript; charset=utf-8', 'Cache-Control' => 'no-cache']);
        }
        if ('/_tether/live' === $path) {
            $origin = $request->getHeaderLine('Origin');
            if ('' !== $origin && !\in_array($origin, $this->origins, true) && \strtolower((string) \preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $origin)) !== \strtolower($request->getHeaderLine('Host'))) {
                return new Response('A page from another site can not open a live connection', ['Content-Type' => 'text/plain'], 403);
            }

            return WebSocket::upgrade($request, fn (WebSocket $ws) => $this->live($ws, $request));
        }

        return $handler->handle($request);
    }

    /**
     * A tab's live connection: the first message mounts the page's root, the rest are events
     * and the results of js() calls.
     */
    private function live(WebSocket $ws, ServerRequestInterface $request): void
    {
        // The browser speaks first once it has the 101 response, so after the framework handled
        // the request and returned it: from here on, the tab is the request's work
        $mount = \json_decode((string) $ws->receive(), true);
        if (!\is_array($mount) || !\is_string($mount['c'] ?? null) || !\is_array($mount['p'] ?? null) || !\hash_equals(self::sign($mount['c'], $mount['p']), (string) ($mount['s'] ?? ''))) {
            $ws->close(1008);

            return;
        }
        $tab = static fn () => self::tab($ws, $mount);
        null === $this->enter ? $tab() : ($this->enter)($request, $tab);
    }

    /**
     * A live tab: mount its root, then events and the results of js() calls, until it closes.
     *
     * @param array{c: class-string<Component>, p: array} $mount
     */
    private static function tab(WebSocket $ws, array $mount): void
    {
        $circuit = new Circuit(
            send: static fn (array $frame) => $ws->send(\json_encode($frame, \JSON_THROW_ON_ERROR)),
            crash: static function (\Throwable $e) use ($ws) {
                Swerve::log()->error('Tether: the tab failed, and starts over: {exception}', ['exception' => $e]);
                $ws->close(1011);
            },
        );
        try {
            if (null === ($html = $circuit->mount($mount['c'], $mount['p']))) {
                return;
            }
            $ws->send(\json_encode(['t' => 'mount', 'html' => $html], \JSON_THROW_ON_ERROR));
            $writer = \phasync::go($circuit->run(...));
            while (null !== ($message = $ws->receive())) {
                $message = \json_decode($message, true);
                if (\is_array($message) && 'return' === ($message['t'] ?? null) && \is_int($message['i'] ?? null)) {
                    $circuit->returned($message['i'], $message['v'] ?? null, isset($message['e']) ? (string) $message['e'] : null);
                } elseif (\is_array($message) && \is_string($message['c'] ?? null) && \is_string($message['m'] ?? null) && \is_array($message['a'] ?? []) && \is_int($message['r'] ?? 0)) {
                    try {
                        $circuit->event($message['c'], $message['m'], $message['a'] ?? [], $message['r'] ?? null);
                    } catch (\InvalidArgumentException $e) {
                        Swerve::log()->warning('Tether: {message}', ['message' => $e->getMessage()]);
                    }
                }
            }
        } finally {
            // The tab is gone, or the worker drains: the writer and every component's coroutines
            // are cancelled
            if (isset($writer) && !$writer->isTerminated()) {
                \phasync::cancel($writer);
            }
            $circuit->close();
        }
    }

    private static function sign(string $class, array $props): string
    {
        return \hash_hmac('sha256', $class . "\0" . \json_encode($props), self::key());
    }

    private static function key(): string
    {
        static $key = null;
        if (null !== $key) {
            return $key;
        }
        if ('' !== ($secret = (string) \getenv('TETHER_SECRET'))) {
            return $key = $secret;
        }
        // Shared by the workers: created once, by whichever gets here first
        $file = \sys_get_temp_dir() . '/tether-' . \md5(\dirname(__DIR__)) . '.key';
        if (!\is_file($file) && false !== ($fp = @\fopen($file, 'x'))) {
            \fwrite($fp, \bin2hex(\random_bytes(32)));
            \fclose($fp);
        }

        return $key = (string) \file_get_contents($file);
    }
}
