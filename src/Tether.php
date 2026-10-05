<?php

namespace Tether;

use phasync\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Http\WebSocket;

/**
 * A live page in an application. Tether::from() is the way: one route answers both the page and
 * its live connection, with nothing else to mount.
 *
 *     return Tether::from($request, fn (Tether $t) => $t->mount(Counter::class, ['start' => 3], 'Counter'));
 *
 * The closure runs for the page's HTML (a GET) and again for each live connection of the browser
 * (a WebSocket upgrade of the same URL, with the same cookies, so the same session and route
 * parameters): it is where the route decides what the page is. The component's props never
 * leave the server: they may be any PHP values. A live tab starts from what the closure returns
 * on its connection, so its state is lost when the connection is, and starts over on reconnect.
 *
 * The older way, for pages that need the client's files at fixed URLs or live pages with
 * navigation between them (see App), is Tether::page() as a route's response, and this class as
 * middleware:
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
    /**
     * @param list<string>                                                 $origins other origins whose pages may open live
     *                                                                              connections, such as "https://app.example.com"
     * @param \Closure(ServerRequestInterface, \Closure(): void): void|null $enter   runs a tab (the closure) as the work of its
     *                                                                              request, after the framework handled it
     * @param Limits                                                       $limits  what a tab may ask of the server (see Limits)
     */
    public function __construct(private readonly array $origins = [], private readonly ?\Closure $enter = null, public readonly bool $live = false, private readonly Limits $limits = new Limits())
    {
    }

    /**
     * The page, and its live connection, at the URL of $request: a GET answers with the page, a
     * WebSocket upgrade of the same URL (what the page's script opens) with the live tab.
     * Call it from a route's handler, with the request it was given; nothing else is needed.
     *
     * $page runs for the page and for every live connection, reconnects included, inside the
     * application's handler, so route parameters, authentication and the session apply. It
     * must give the same answer for the same route, query and session; work that must happen
     * once checks `$t->live`. It returns a Page (`$t->mount(Counter::class, $props, 'Title')`) or
     * a response: sent as it is for the page; for a live connection, a redirect sends the
     * browser there, and any other response (not found, not allowed) leaves the page static.
     * Anything else is a LogicException.
     *
     * A live tab runs after the upgrade was answered, outside the framework's request: resolve
     * what its components need (the user, ids) in $page and pass it as props, or read it from
     * Component::request(), a snapshot of the upgrade request with the attributes the framework's
     * middleware set. $enter makes the request the framework's current one for the tab, as for
     * the middleware: `enter: RequestDispatcher::within(...)` with mini.
     *
     * $shell makes the whole HTML document from the root's HTML, the script block and the Page,
     * when the application's template owns the page. The script block belongs in the head (it
     * is a deferred module: the application's defer scripts that call Tether.hook() run after
     * it); a shell that leaves it out is an error. $nonce is for a Content-Security-Policy that
     * needs one on inline scripts. $origins: other origins whose pages may open the live
     * connection, as for the middleware; the Origin must be this host otherwise. $limits is
     * what a tab may ask of the server: events a second, handlers running, message size.
     *
     * @param \Closure(Tether): (Page|ResponseInterface)                  $page
     * @param \Closure(string, string, Page): string|null                 $shell
     * @param list<string>                                                $origins
     * @param \Closure(ServerRequestInterface, \Closure(): void): void|null $enter
     */
    public static function from(ServerRequestInterface $request, \Closure $page, ?\Closure $shell = null, array $origins = [], ?\Closure $enter = null, string $nonce = '', Limits $limits = new Limits()): ResponseInterface
    {
        $method = $request->getMethod();
        if ('GET' !== $method && 'HEAD' !== $method) {
            return new Response(405, ['Content-Type' => 'text/plain', 'Allow' => 'GET, HEAD'], 'Method not allowed');
        }
        if ('GET' === $method && 'websocket' === \strtolower($request->getHeaderLine('Upgrade'))) {
            if (null !== ($refused = Live::refuseOrigin($request, $origins))) {
                return $refused;
            }
            $result = self::answer($page(new self(live: true)));
            if ($result instanceof Page) {
                return WebSocket::from($request, static function (WebSocket $ws) use ($request, $result, $enter, $limits) {
                    // The browser's first message, sent on open, is the barrier: the 101 is out and the handler returned
                    if (null === $ws->receive()) {
                        return;
                    }
                    $tab = static fn () => Live::tab($ws, $result, null, $request, $limits);
                    null === $enter ? $tab() : $enter($request, $tab);
                });
            }

            // A browser can not read a failed upgrade's status: accept it, and say what it was
            return WebSocket::from($request, static function (WebSocket $ws) use ($result) {
                $location = $result->getHeaderLine('Location');
                if ($result->getStatusCode() >= 300 && $result->getStatusCode() < 400 && '' !== $location) {
                    $ws->send(\json_encode(['t' => 'frame', 'nav' => ['load' => $location]], \JSON_THROW_ON_ERROR));
                } else {
                    $ws->end(1008);
                }
            });
        }
        $result = self::answer($page(new self()));
        if ($result instanceof ResponseInterface) {
            return $result;
        }
        $html = ($shell ?? Live::shell(...))((new Circuit(request: $request))->mount($result->class, $result->props), Live::scripts($nonce), $result);
        if (!\str_contains($html, 'data-tether')) {
            throw new \LogicException('The shell must place its $scripts argument in the document: the page is dead without it');
        }

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    /** A Page for the closure given to from() to return: `$t->mount(Counter::class, $props, 'Title')`. */
    public function mount(string $class, array $props = [], string $title = '', string $head = ''): Page
    {
        return new Page($class, $props, $title, $head);
    }

    private static function answer(mixed $result): Page|ResponseInterface
    {
        if (!$result instanceof Page && !$result instanceof ResponseInterface) {
            throw new \LogicException('The closure given to Tether::from() must return a Page or a response, not ' . \get_debug_type($result));
        }

        return $result;
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
        $html = (new Circuit())->mount($class, $props);

        return Live::document($html, ['live' => '/_tether/live', 'c' => $class, 'p' => $props, 's' => self::sign($class, $props)], $title, $head, '/_tether');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (\str_starts_with($path, '/_tether/') && null !== ($asset = Live::asset(\substr($path, 9)))) {
            return $asset;
        }
        if ('/_tether/live' === $path) {
            return Live::refuseOrigin($request, $this->origins) ?? WebSocket::from($request, fn (WebSocket $ws) => $this->live($ws, $request));
        }

        return $handler->handle($request);
    }

    /**
     * A tab's live connection: the first message says what to mount (signed), the rest are
     * events and the browser's answers to calls into it.
     */
    private function live(WebSocket $ws, ServerRequestInterface $request): void
    {
        // The browser speaks first once it has the 101 response, so after the framework handled
        // the request and returned it: from here on, the tab is the request's work
        $mount = \json_decode((string) $ws->receive(), true);
        if (!\is_array($mount) || !\is_string($mount['c'] ?? null) || !\is_array($mount['p'] ?? null) || !\hash_equals(self::sign($mount['c'], $mount['p']), (string) ($mount['s'] ?? ''))) {
            $ws->end(1008);

            return;
        }
        $limits = $this->limits;
        $tab = static fn () => Live::tab($ws, new Page($mount['c'], $mount['p']), null, $request, $limits);
        null === $this->enter ? $tab() : ($this->enter)($request, $tab);
    }

    private static function sign(string $class, array $props): string
    {
        return \hash_hmac('sha256', $class . "\0" . \json_encode($props), self::key());
    }

    private static function key(): string
    {
        static $key = null;

        return $key ??= self::loadKey(\sys_get_temp_dir() . '/tether-' . \md5(\dirname(__DIR__)) . '.key');
    }

    /**
     * TETHER_SECRET, or else the key the workers share in $file: made by whichever gets there
     * first, complete and private from the moment it exists.
     */
    private static function loadKey(string $file): string
    {
        $key = (string) \getenv('TETHER_SECRET');
        if ('' === $key) {
            if (!\is_file($file)) {
                $temp = \tempnam(\dirname($file), 'tether-'); // mode 0600
                \file_put_contents($temp, \bin2hex(\random_bytes(32)));
                // link() fails when another worker made the file first, and never replaces it
                @\link($temp, $file);
                \unlink($temp);
            }
            $key = (string) \file_get_contents($file);
        }
        if (\strlen($key) < 32) {
            throw new \RuntimeException('Tether\'s signing key must be at least 32 bytes: set TETHER_SECRET to a long random string, or delete the key file ' . $file);
        }

        return $key;
    }
}
