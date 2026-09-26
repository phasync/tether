<?php

namespace Tether;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Http\Message\Response;
use Swerve\Swerve;
use Tether\Transport\WebSocket;

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

        return new Response(\fopen(\dirname(__DIR__) . '/resources/' . self::ASSETS[$name], 'r'), ['Content-Type' => 'text/javascript; charset=utf-8', 'Cache-Control' => 'no-cache']);
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

        return new Response(<<<HTML
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
            HTML, ['Content-Type' => 'text/html; charset=utf-8']);
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

        return new Response('A page from another site can not open a live connection', ['Content-Type' => 'text/plain'], 403);
    }

    /**
     * A live tab: mount $page, then events, the results of js() calls and navigation, until the
     * connection closes.
     *
     * @param (\Closure(string): array{0: ?Page, 1: string})|null $resolve a URL's page, or null
     *                                                                     for a full page load; null: every navigation is a full page load
     */
    public static function tab(WebSocket $ws, Page $page, ?\Closure $resolve): void
    {
        $circuit = new Circuit(
            send: static fn (array $frame) => $ws->send(\json_encode($frame, \JSON_THROW_ON_ERROR)),
            crash: static function (\Throwable $e) use ($ws) {
                Swerve::log()->error('Tether: the tab failed, and starts over: {exception}', ['exception' => $e]);
                $ws->close(1011);
            },
            resolve: $resolve,
        );
        try {
            if (null === ($html = $circuit->mount($page->class, $page->props))) {
                return;
            }
            $ws->send(\json_encode(['t' => 'mount', 'html' => $html], \JSON_THROW_ON_ERROR));
            $writer = \phasync::go($circuit->run(...));
            while (null !== ($message = $ws->receive())) {
                $message = \json_decode($message, true);
                if (!\is_array($message)) {
                    continue;
                }
                if ('return' === ($message['t'] ?? null) && \is_int($message['i'] ?? null)) {
                    $circuit->returned($message['i'], $message['v'] ?? null, isset($message['e']) ? (string) $message['e'] : null);
                } elseif ('navigate' === ($message['t'] ?? null) && \is_string($message['u'] ?? null)) {
                    $circuit->navigate($message['u'], (bool) ($message['p'] ?? true));
                } elseif (\is_string($message['c'] ?? null) && \is_string($message['m'] ?? null) && \is_array($message['a'] ?? []) && \is_int($message['r'] ?? 0)) {
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
}
