# Tether and Blazor Server

For readers who know Blazor Server. Both keep a component's state on the server, over one
connection per tab, and send the browser what changed. Differences first, then what maps to
what.

## Differences that matter

- **Handlers interleave.** Blazor runs a circuit's code one piece at a time. A Tether handler
  that waits (`phasync::sleep()`, a query, a call to the browser) lets the next event's handler
  start, so two clicks can be inside the same component at once. Assign state before waiting
  ([Events](events-and-javascript.md#handlers)); `serial` and `drop` on the binding pace events
  per binding.
- **Markup is a PHP string.** `render()` returns HTML that starts with one element; there is
  no render tree and no `RenderFragment`. Templates are your choice of engine
  ([Components](components.md#templates)).
- **The page is rendered twice.** The first HTML is an ordinary request; the live tab then
  creates the components again, so `mount()` runs twice ([Components](components.md#lifecycle)).
- **A reconnect starts over.** There is no circuit retention: after a lost connection or a
  deploy, components mount from scratch and what was typed is replaced by what the server
  renders. State that must survive goes in storage or the session.
- **No DI container.** Components are built from props, and services are whatever your
  framework offers ([State](state.md)).

## Equivalents

| Blazor Server | Tether |
|---|---|
| `[Parameter]` | a public property, set by the parent's `child()` call every render |
| `@key` | `child(Class::class, $props, key: ...)` |
| `EventCallback` | a `Closure` prop the parent passes ([Components](components.md#props-and-children)) |
| `StateHasChanged()` | `requestRender()`; a handler renders by itself when it returns |
| `ShouldRender()` | `#[NoRender]` on a handler that changed nothing the page shows |
| `OnInitialized` | `mount()`, synchronous, runs once per instance |
| `OnInitializedAsync` | `run()`: render a placeholder, load, `requestRender()` ([Components](components.md#mount)) |
| `OnParametersSet` | `propsChanged($old)` |
| `IDisposable` / `IAsyncDisposable` | `dispose()`, after the component's coroutines were cancelled |
| `OnAfterRender` | a hook's `updated()`, or `awaitRender()` before a browser call |
| `RendererInfo.IsInteractive` | `isLive()` |
| `@onclick`, `@oninput`, ... | `tether-click`, `tether-input`, `tether-on-<any DOM event>`, with modifiers ([attributes](attributes.md)) |
| `@onclick:preventDefault`, `:stopPropagation` | the `prevent` and `stop` modifiers |
| `KeyboardEventArgs`, `MouseEventArgs`, ... | `Tether\Event\KeyboardEventArgs` and the rest, as the last handler parameter |
| `@bind`, `@bind:event` | `$this->bind('prop')` with `#[Bind]`: string, int, float, bool, array, backed enum |
| `@ref` on an element | `tether-ref="name"`, `$this->browser()->ref('name')` |
| `ErrorBoundary` | `Tether\ErrorBoundary` on a component; without one the tab starts over |
| `IJSRuntime.InvokeAsync`, `InvokeVoidAsync` | `$this->browser()->call('path.to.fn', ...$args)` |
| `IJSObjectReference`, `import` | `Tether\JsObject`, `$this->browser()->import($url)` |
| `[JSInvokable]`, `DotNetObjectReference` | `#[Invokable]` on a handler, `Tether.invoke()` or `this.invoke()` in a hook |
| `JSException` | `Tether\JsException` and its subclasses |
| `NavigationManager.NavigateTo` | `$this->navigate($url)`: a page change inside an [App](apps.md), a full load for any other URL (also from a `from()` page) |
| `Router`, link interception | an [App](apps.md) with routes keeps the circuit across pages; on a `from()` page [`tether-boost`](running.md#navigation-without-a-reload) fetches and morphs the next page without a reload, but with no continuity: the new page opens its own connection and its state starts over |
| Reconnect UI (`components-reconnect-*`) | `tether-offline` and `tether-crashed` on `<html>`, the `tetherconnection` event, `Tether.reconnect()` |
| Keep-alive pings, `ClientTimeoutInterval` | a heartbeat both ways: the browser drops a silent connection and reconnects, the server closes a tab that sent nothing for `Limits::$clientTimeout` ([Errors](errors.md#without-a-boundary-the-tab-starts-over)) |
| Hub message size limit | `Limits::$bytes`; more limits in `Tether\Limits` |
| bUnit | `Tether\Testing\Tab` ([Testing](testing.md)) |

## Workarounds

Things Blazor has a name for, done with interop.

**A loading state.** Render before the slow part; the page shows the intermediate frame.

```php
public function load(): void
{
    $this->state = 'loading';
    $this->requestRender();
    $this->rows = $this->slowQuery();   // waits
    $this->state = 'done';
}
```

**Time zone, media queries, the address.** Read them from the browser once, in a handler or
`run()`; `mount()` cannot call the browser. `executeString()` needs a page that allows
`'unsafe-eval'`; `call()` and property access work under a strict CSP.

```php
$zone  = $this->browser()->executeString('return Intl.DateTimeFormat().resolvedOptions().timeZone;');
$wide  = $this->browser()->executeString('return matchMedia(q).matches;', ['q' => '(min-width: 800px)']);
$where = $this->browser()->window->location->href;
```

**Cancelling a JavaScript call** (`CancellationToken`). Keep an `AbortController` in the page.

```php
$request = $this->browser()->executeString('window.job = new AbortController(); return fetch(url, {signal: window.job.signal});', ['url' => '/export']);
// ... later, from another handler:
$this->browser()->executeString('window.job.abort();');   // the Promise rejects with AbortError
```

**Content in a child** (`ChildContent`). Pass the markup as a string prop, escaped where it was
made, and write it where the child renders it.

**JavaScript that owns an element** (a map, an editor): `tether-ignore` and a hook
([Events](events-and-javascript.md#hooks-the-browser-side-of-a-component)). A hook calls
back with `this.invoke()`.

**Page title and focus.** `$this->browser()->document->title = '...'`; after a render,
`$this->awaitRender(); $this->browser()->find('h1')->focus();`.

**Cookies and sign-in.** An ordinary form posted to an ordinary route that sets the cookie
and redirects ([State](state.md#signing-in)); the live connection carries the cookies it had
when it connected.

## Not planned

- Circuit retention, `PersistentComponentState`, resuming a circuit after a reconnect.
- `RenderFragment` and templated components; `CascadingValue`; dependency injection into
  components.
- `EditForm`, data annotations and `InputFile` uploads. A form's text fields reach a handler
  with `tether-submit`; validate in PHP. A file input's chosen files arrive as name, size and
  type only.
- Optimistic UI and client-side pending states: the page shows what the server rendered.
- Blazor WebAssembly, streaming rendering, and transports other than WebSocket.
