<?php

namespace Tether;

use phasync\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Http\WebSocket;
use Swerve\Swerve;

/**
 * What Tether (one live page) and App (live pages with navigation) share: the page around the
 * components, the browser client's files, the live endpoint's origin check, and a live tab.
 *
 * @internal
 */
final class Live
{
    private const ASSETS = [
        'tether.js'    => 'tether.js',
        'idiomorph.js' => 'idiomorph.min.js',
    ];

    /** The browser client's file $name, or null when there is none. */
    public static function asset(string $name): ?ResponseInterface
    {
        if (!isset(self::ASSETS[$name])) {
            return null;
        }

        return new Response(200, ['Content-Type' => 'text/javascript; charset=utf-8', 'Cache-Control' => 'no-cache'], \fopen(\dirname(__DIR__) . '/resources/' . self::ASSETS[$name], 'r'));
    }

    /**
     * The page: the components' first HTML, and the client, which then connects and mounts them
     * live.
     *
     * @param array<string, mixed> $mount  for the client: the live endpoint, and what to mount
     * @param string               $assets the URL path the client's files are served under
     */
    public static function document(string $html, array $mount, string $title, string $head, string $assets): ResponseInterface
    {
        $title = \htmlspecialchars($title);
        $mount = \json_encode($mount, \JSON_THROW_ON_ERROR | \JSON_HEX_TAG | \JSON_HEX_AMP);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], <<<HTML
            <!doctype html>
            <html>
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$title}</title>
            <script src="{$assets}/idiomorph.js" defer></script>
            <script src="{$assets}/tether.js" defer></script>
            {$head}
            </head>
            <body>
            {$html}
            <script type="application/json" id="tether-mount">{$mount}</script>
            </body>
            </html>
            HTML);
    }

    /**
     * The client for a Tether::from() page: one inline module script, idiomorph then tether.js
     * (modules defer: it is safe in the head, and runs before the application's defer scripts).
     */
    public static function scripts(string $nonce): string
    {
        static $client = null;
        $client ??= \file_get_contents(\dirname(__DIR__) . '/resources/idiomorph.min.js') . "\n" . \file_get_contents(\dirname(__DIR__) . '/resources/tether.js');
        $nonce = '' === $nonce ? '' : ' nonce="' . \htmlspecialchars($nonce) . '"';

        return "<script type=\"module\" data-tether{$nonce}>\n{$client}\n</script>";
    }

    /** The default document of a Tether::from() page. */
    public static function shell(string $root, string $scripts, Page $page): string
    {
        $title = \htmlspecialchars($page->title);

        return <<<HTML
            <!doctype html>
            <html>
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$title}</title>
            {$scripts}
            {$page->head}
            </head>
            <body>
            {$root}
            </body>
            </html>
            HTML;
    }

    /**
     * The live endpoint's answer to a page from another site, which could otherwise open a
     * connection with the visitor's cookies: 403. Null when the Origin is this host, one of
     * $origins, or absent (not a browser).
     *
     * @param list<string> $origins
     */
    public static function refuseOrigin(ServerRequestInterface $request, array $origins): ?ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if ('' === $origin || \in_array($origin, $origins, true) || \strtolower((string) \preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $origin)) === \strtolower($request->getHeaderLine('Host'))) {
            return null;
        }

        return new Response(403, ['Content-Type' => 'text/plain'], 'A page from another site can not open a live connection');
    }

    /**
     * A live tab: mount $page, then events, the results of js() calls and navigation, until the
     * connection closes.
     *
     * @param (\Closure(string): array{0: ?Page, 1: string})|null $resolve a URL's page, or null
     *                                                                     for a full page load; null: every navigation is a full page load
     */
    public static function tab(WebSocket $ws, Page $page, ?\Closure $resolve, ?ServerRequestInterface $request = null, Limits $limits = new Limits()): void
    {
        $circuit = new Circuit(
            send: static fn (array $frame) => $ws->send(\json_encode($frame, \JSON_THROW_ON_ERROR)),
            crash: static function (\Throwable $e) use ($ws) {
                Swerve::log()->error('Tether: the tab failed, and starts over: {exception}', ['exception' => $e]);
                $ws->end(1011);
            },
            resolve: $resolve,
            request: $request,
            limits: $limits,
            abuse: static fn () => $ws->end(4429),
        );
        try {
            if (null === ($html = $circuit->mount($page->class, $page->props))) {
                return;
            }
            $ws->send(\json_encode(['t' => 'mount', 'html' => $html, 'lim' => ['eps' => $limits->eventsPerSecond, 'burst' => $limits->burst, 'bytes' => $limits->bytes]], \JSON_THROW_ON_ERROR));
            $writer = \phasync::go($circuit->run(...));
            while (null !== ($message = $ws->receive())) {
                $message = \json_decode($message, true);
                if (!\is_array($message)) {
                    continue;
                }
                if ('return' === ($message['t'] ?? null) && \is_int($message['i'] ?? null)) {
                    if (!$circuit->admit()) {
                        continue;
                    }
                    $circuit->returned($message['i'], $message['v'] ?? null, isset($message['e']) ? (string) $message['e'] : null);
                } elseif ('navigate' === ($message['t'] ?? null) && \is_string($message['u'] ?? null)) {
                    if (!$circuit->admit()) {
                        continue;
                    }
                    $circuit->navigate($message['u'], (bool) ($message['p'] ?? true));
                } elseif (\is_string($message['c'] ?? null) && \is_string($message['m'] ?? null) && \is_array($message['a'] ?? []) && \is_int($message['r'] ?? 0) && \is_array($message['e'] ?? [])) {
                    try {
                        $circuit->event($message['c'], $message['m'], $message['a'] ?? [], $message['r'] ?? null, $message['e'] ?? []);
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
}
