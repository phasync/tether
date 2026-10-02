<?php

namespace Tether;

use phasync\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Http\WebSocket;

/**
 * Live pages with navigation between them: a PSR-15 request handler, mounted where the
 * framework routes a path and everything below it (with mini: `_routes/__DEFAULT__.php`, or
 * `_routes/chat/__DEFAULT__.php` for /chat/..., returning `new MyApp()`).
 *
 *     final class Chat extends Tether\App
 *     {
 *         #[Route('/')]
 *         public function home(): Page
 *         {
 *             return new Page(Layout::class, ['channel' => null], 'Chat');
 *         }
 *
 *         #[Route('/channels/{id}')]
 *         public function channel(int $id): Page|ResponseInterface
 *         {
 *             return new Page(Layout::class, ['channel' => $id], "#$id");
 *         }
 *     }
 *
 * Over HTTP (a first visit, a reload, a shared link), a route's Page is rendered as a page;
 * another response goes out as it is; no route is 404. Once live, following a link below the
 * App, the browser's back and forward, and Component::navigate() go over the tab's connection:
 * the route is resolved in the tab, and
 *
 * - the same root class gets the new props: only what they change renders, and every other
 *   component keeps its state and coroutines (a call, a draft, a sidebar);
 * - another root class replaces the root;
 * - a redirect below the App is followed; anything else (a URL outside the App, a response
 *   that is not a Page, no route) is a full page load, where HTTP says what it is.
 *
 * The App serves the client's files and the live connection below itself, at `.tether/`.
 * $origins and $enter are as for Tether.
 */
abstract class App implements RequestHandlerInterface
{
    /** @var list<array{0: string, 1: \ReflectionMethod}> path pattern, route method */
    private array $routes = [];

    /**
     * @param list<string>                                                 $origins other origins whose pages may open live connections
     * @param \Closure(ServerRequestInterface, \Closure(): void): void|null $enter   runs a tab as the work of its request
     */
    public function __construct(private readonly array $origins = [], private readonly ?\Closure $enter = null)
    {
        foreach ((new \ReflectionObject($this))->getMethods() as $method) {
            foreach ($method->getAttributes(Route::class) as $attribute) {
                $pattern = \preg_replace_callback('#\\\\\{(\w+)\\\\\}#', static fn ($m) => "(?P<{$m[1]}>[^/]+)", \preg_quote($attribute->newInstance()->path, '#'));
                $this->routes[] = ["#^{$pattern}$#", $method];
            }
        }
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Relative to where the App is mounted: a framework that routes a prefix to the App
        // gives it the rest as the request target (mini does); the URI keeps the whole path
        $path = \parse_url($request->getRequestTarget(), \PHP_URL_PATH) ?: '/';
        $full = $request->getUri()->getPath();
        $base = \str_ends_with($full, $path) ? \rtrim(\substr($full, 0, \strlen($full) - \strlen($path)), '/') : '';
        if (\str_starts_with($path, '/.tether/')) {
            if ('/.tether/live' === $path) {
                return Live::refuseOrigin($request, $this->origins) ?? WebSocket::from($request, fn (WebSocket $ws) => $this->live($ws, $request, $base));
            }

            return Live::asset(\substr($path, 9)) ?? new Response(404, ['Content-Type' => 'text/plain'], 'Not found');
        }
        if ('GET' !== $request->getMethod() && 'HEAD' !== $request->getMethod()) {
            return new Response(405, ['Content-Type' => 'text/plain', 'Allow' => 'GET, HEAD'], 'Method not allowed');
        }
        $result = $this->route($path, $request);
        if (!$result instanceof Page) {
            return $result;
        }

        return Live::document((new Circuit())->mount($result->class, $result->props), ['live' => "$base/.tether/live", 'base' => $base], $result->title, $this->head(), "$base/.tether");
    }

    /** HTML for the head of every page: the application's styles, and its scripts (defer). */
    protected function head(): string
    {
        return '';
    }

    /** $path's route: its Page or response, or 404. */
    private function route(string $path, ServerRequestInterface $request): Page|ResponseInterface
    {
        foreach ($this->routes as [$pattern, $method]) {
            if (!\preg_match($pattern, $path, $match)) {
                continue;
            }
            $args = [];
            foreach ($method->getParameters() as $parameter) {
                $type = (string) $parameter->getType();
                if (ServerRequestInterface::class === \ltrim($type, '?')) {
                    $args[] = $request;
                } elseif (isset($match[$parameter->getName()])) {
                    $value = \rawurldecode($match[$parameter->getName()]);
                    $args[] = match (\ltrim($type, '?')) {
                        'int'   => \preg_match('/^-?\d+$/', $value) ? (int) $value : null,
                        'float' => \is_numeric($value) ? (float) $value : null,
                        default => $value,
                    };
                    if (null === \end($args)) {
                        continue 2; // not of the parameter's type: not this route
                    }
                } else {
                    $args[] = $parameter->getDefaultValue();
                }
            }

            return $method->invokeArgs($this, $args);
        }

        return new Response(404, ['Content-Type' => 'text/plain'], 'Not found');
    }

    /**
     * A tab's live connection: the first message is the URL the tab shows, the rest are
     * events, the results of js() calls, and navigation.
     */
    private function live(WebSocket $ws, ServerRequestInterface $request, string $base): void
    {
        $mount = \json_decode((string) $ws->receive(), true);
        if (!\is_string($mount['u'] ?? null)) {
            $ws->end(1008);

            return;
        }
        $tab = function () use ($ws, $request, $base, $mount) {
            $resolve         = fn (string $url) => $this->resolve($url, $request, $base);
            [$page, $url]    = $resolve($mount['u']);
            if (null === $page) {
                // The tab's URL is no longer a page of the App (signed out, say): load it
                $ws->send(\json_encode(['t' => 'frame', 'nav' => ['load' => $url]], \JSON_THROW_ON_ERROR));

                return;
            }
            Live::tab($ws, $page, $resolve);
        };
        null === $this->enter ? $tab() : ($this->enter)($request, $tab);
    }

    /**
     * The page at $url, in a live tab: following redirects below the App. [null, $url] when it
     * takes a full page load.
     *
     * @return array{0: ?Page, 1: string}
     */
    private function resolve(string $url, ServerRequestInterface $request, string $base): array
    {
        for ($hops = 0; $hops < 5; ++$hops) {
            $parts = \parse_url($url);
            $path  = $parts['path'] ?? '/';
            if ((isset($parts['host']) && \strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')) !== \strtolower($request->getHeaderLine('Host')))
                || ('' !== $base && $path !== $base && !\str_starts_with($path, "$base/"))) {
                return [null, $url];
            }
            $query    = $parts['query'] ?? '';
            $relative = '' === ($rest = \substr($path, \strlen($base))) ? '/' : $rest;
            \parse_str($query, $params);
            $url = $path . ('' !== $query ? "?$query" : '');
            try {
                $result = $this->route($relative, $request
                    ->withMethod('GET')
                    ->withUri($request->getUri()->withPath($path)->withQuery($query))
                    ->withRequestTarget($relative . ('' !== $query ? "?$query" : ''))
                    ->withQueryParams($params));
            } catch (\Throwable) {
                return [null, $url]; // the full page load shows it, as HTTP does
            }
            if ($result instanceof Page) {
                return [$result, $url];
            }
            if ($result->getStatusCode() < 300 || $result->getStatusCode() >= 400 || '' === ($location = $result->getHeaderLine('Location'))) {
                return [null, $url];
            }
            $url = $location;
        }

        return [null, $url];
    }
}
