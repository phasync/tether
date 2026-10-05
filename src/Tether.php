<?php

namespace Tether;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Http\WebSocket;

/**
 * One live page in an application: Tether::page() as a route's response, and this middleware
 * for the client's files and the live connection. For live pages with navigation between them,
 * see App.
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
     * events and the results of js() calls.
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
        $tab = static fn () => Live::tab($ws, new Page($mount['c'], $mount['p']), null);
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
