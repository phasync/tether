# Apps and navigation

An App is a class with routes to live pages. Once a tab is live, moving between the App's pages
goes over the tab's connection instead of loading a new page: the components the pages share
stay as they are, with their state and their coroutines. A sidebar keeps its scroll position, a
call keeps going, a half-typed message stays half-typed.

```php
namespace App;

use Psr\Http\Message\ResponseInterface;
use Tether\App;
use Tether\Page;
use Tether\Route;

final class Chat extends App
{
    #[Route('/')]
    public function home(): Page
    {
        return new Page(Layout::class, ['channel' => null], 'Chat');
    }

    #[Route('/channels/{id}')]
    public function channel(int $id): Page|ResponseInterface
    {
        if (!Channel::exists($id)) {
            return new \phasync\Psr\Response(404, [], 'No such channel');
        }

        return new Page(Layout::class, ['channel' => $id], Channel::name($id));
    }

    #[Route('/settings')]
    public function settings(): Page
    {
        return new Page(Settings::class, [], 'Settings');
    }

    protected function head(): string
    {
        return '<link rel="stylesheet" href="/app.css"><script src="/app.js" defer></script>';
    }
}
```

## Routes

- `#[Route($path)]` on a public method: GET requests for `$path`, relative to where the App is
  mounted. A method may have several.
- `{name}` matches one path segment and goes to the parameter `$name`, converted to its type:
  `int`, `float` or `string`. A segment that doesn't convert (`/channels/abc` for `int $id`) is
  not a match: the next route is tried.
- A parameter typed `ServerRequestInterface` gets the request: query parameters, headers.
- A method returns a `Page`, or any PSR-7 response (a redirect, a 404, a download). No route is
  404; other methods than GET and HEAD are 405. Forms that post, uploads and APIs belong in your
  framework's routes, next to the App.
- `head()` returns HTML for every page's `<head>`: styles, and scripts with `defer` (so they run
  after Tether's client, and can register hooks).

`new Page($class, $props, $title)`: the root component, its props, and the page title (text).

## Mounting

An App is a PSR-15 request handler, mounted where your framework routes a path and everything
below it.

- mini: `_routes/__DEFAULT__.php` (the whole site) or `_routes/chat/__DEFAULT__.php` (below
  `/chat/`), returning `new App\Chat(enter: RequestDispatcher::within(...))`. mini gives the
  App paths relative to the directory; the App finds its base path from that.
- Slim: `$app->any('/[{path:.*}]', new App\Chat())`, with route paths from the site's root.

The App serves Tether's client and its live endpoint below itself (`.tether/`), so it needs no
middleware. Its constructor takes `origins` and `enter`, as described in
[Running Tether](running.md).

## Navigation

Over HTTP (a first visit, a reload, a link from elsewhere), a route's page is rendered as an
ordinary page. Once the tab is live:

- **Links** below the App are followed over the connection: `<a href="/channels/42">`. Not links
  with `target`, `download`, `tether-reload` or `tether-click`, clicks with a modifier key (a
  new tab), or links to a `#fragment` of the same page.
- **Back and forward** too.
- **From the server**: `$this->navigate('/channels/42')` in an event handler or `run()` (after
  creating a channel, say).

The tab resolves the route, then:

- **Same root class**: the root gets the new props, and renders. Its children render if their
  props changed, as always: a child keyed by the channel id is a new component for each channel;
  a child without a key, or with the same key, stays.
- **Another root class**: the old root is unmounted (its coroutines cancelled), and the new one
  mounted in its place.
- **A redirect** to a URL below the App is followed the same way (the address bar shows where
  it ended up).
- **Anything else** (a URL outside the App, a response that is not a page, no route, a route that
  throws) is loaded by the browser, the ordinary way: the user sees what HTTP says it is.

The address bar and the title follow, in the same frame as the new page.

## Layouts

Give pages that share a frame the same root class, and let props choose what's in it:

```php
final class Layout extends Component
{
    public ?int $channel = null;

    public function render(): string
    {
        $main = null === $this->channel
            ? '<p>Pick a channel</p>'
            : $this->child(Messages::class, ['channel' => $this->channel], key: "channel:{$this->channel}");

        return <<<HTML
            <div class="app">
              {$this->child(ServerList::class)}
              {$this->child(ChannelList::class, ['selected' => $this->channel])}
              <main>{$main}</main>
              {$this->child(VoiceCall::class, key: 'call')}
            </div>
            HTML;
    }
}
```

Moving from one channel to another re-renders `Layout`: `ChannelList` gets its new `selected`
prop, `Messages` for the old channel leaves and one for the new channel mounts, and `VoiceCall`
(same key, no props) is left exactly as it was, call and all.

## Links and URLs

- Write ordinary links: they work before the tab is live (as page loads), and in a browser
  without JavaScript.
- Mark the current page in the layout from its props (`selected`), not from the URL in the
  browser.
- A route is a public URL: check permissions in the route (redirect to sign-in) and again in the
  components' handlers.
