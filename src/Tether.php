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
 */
final class Tether implements MiddlewareInterface
{
    private const ASSETS = [
        '/_tether/tether.js'    => 'tether.js',
        '/_tether/idiomorph.js' => 'idiomorph.min.js',
    ];

    /**
     * @param class-string<Component> $class
     * @param array<string, mixed>    $props  JSON-encodable
     */
    public static function page(string $class, array $props = [], string $title = ''): ResponseInterface
    {
        $html  = (new Circuit())->mount($class, $props);
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
            return WebSocket::upgrade($request, self::live(...));
        }

        return $handler->handle($request);
    }

    /**
     * A tab's live connection: the first message mounts the page's root, the rest are events.
     */
    private static function live(WebSocket $ws): void
    {
        $mount = \json_decode((string) $ws->receive(), true);
        if (!\is_array($mount) || !\is_string($mount['c'] ?? null) || !\is_array($mount['p'] ?? null) || !\hash_equals(self::sign($mount['c'], $mount['p']), (string) ($mount['s'] ?? ''))) {
            $ws->close(1008);

            return;
        }
        $circuit = new Circuit(static fn (array $frame) => $ws->send(\json_encode($frame, \JSON_THROW_ON_ERROR)));
        try {
            $html = $circuit->mount($mount['c'], $mount['p']);
            $ws->send(\json_encode(['t' => 'mount', 'html' => $html], \JSON_THROW_ON_ERROR));
            while (null !== ($message = $ws->receive())) {
                $event = \json_decode($message, true);
                if (!\is_array($event) || !\is_string($event['c'] ?? null) || !\is_string($event['m'] ?? null) || !\is_array($event['a'] ?? [])) {
                    continue;
                }
                try {
                    $circuit->event($event['c'], $event['m'], \array_values($event['a'] ?? []));
                } catch (\InvalidArgumentException $e) {
                    Swerve::log()->warning('Tether: {message}', ['message' => $e->getMessage()]);
                }
            }
        } finally {
            // The tab is gone, or the worker drains: every component's coroutines are cancelled
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
