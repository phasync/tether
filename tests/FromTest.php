<?php

use phasync\Psr\Response;
use phasync\Psr\ServerRequest;
use phasync\Psr\StringStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tether\Circuit;
use Tether\Component;
use Tether\Live;
use Tether\Page;
use Tether\Tether;

// Tether::from() with plain PSR-7 requests: no framework

final class Greeting extends Component
{
    public string $who = '';

    public ?object $user = null;

    public function mount(): void
    {
        $GLOBALS['from_mounted'][] = $this->request()->getUri()->getPath();
    }

    public function path(): string
    {
        return $this->request()->getUri()->getPath();
    }

    public function render(): string
    {
        return '<p>' . htmlspecialchars($this->who . ($this->user->name ?? '')) . '</p>';
    }
}

/** A request for $target; a WebSocket upgrade with $frames (the client's messages) as its body when $upgrade. */
function from_request(string $target, array $headers = [], string $method = 'GET', bool $upgrade = false, ?array $frames = null): ServerRequestInterface
{
    $frames ??= ['{"v":"' . Live::version() . '"}'];
    $headers += ['Host' => 'example.test'];
    if ($upgrade) {
        $headers += ['Upgrade' => 'websocket', 'Connection' => 'Upgrade', 'Sec-WebSocket-Version' => '13', 'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ=='];
    }
    $body = '';
    foreach ($frames as $frame) {
        $mask = 'abcd';
        $body .= "\x81" . chr(0x80 | strlen($frame)) . $mask . ($frame ^ str_repeat($mask, intdiv(strlen($frame), 4) + 1));
    }

    // The client stays a moment after its last message: a tab ends when the connection does
    $stream = new class($body) extends StringStream {
        public function read($length): string
        {
            if ('' === ($bytes = parent::read($length))) {
                phasync::sleep(0.05);
            }

            return $bytes;
        }
    };

    return new ServerRequest($method, $target, $stream, $headers);
}

/** The messages and the close code the server sent on the 101's connection, until it ends. */
function from_frames(ResponseInterface $response): array
{
    $bytes = '';
    $body  = $response->getBody();
    while (!$body->eof()) {
        $bytes .= $body->read(65536);
    }
    $messages = [];
    $close    = null;
    while ('' !== $bytes) {
        $opcode = ord($bytes[0]) & 0x0F;
        $length = ord($bytes[1]);
        $offset = 2;
        if (126 === $length) {
            $length = unpack('n', substr($bytes, 2, 2))[1];
            $offset = 4;
        }
        $payload = substr($bytes, $offset, $length);
        $bytes   = substr($bytes, $offset + $length);
        if (8 === $opcode) {
            $close = unpack('n', $payload)[1];
        } elseif (1 === $opcode) {
            $messages[] = json_decode($payload, true);
        }
    }

    return [$messages, $close];
}

/** Run the closure's page through from(): [response, number of closure runs, the live flags seen]. */
function from_run(ServerRequestInterface $request, Closure $page, array $options = []): array
{
    $seen = [];
    $GLOBALS['from_mounted'] = [];
    $response = phasync::run(function () use ($request, $page, $options, &$seen) {
        return Tether::from($request, function (Tether $t) use ($page, &$seen) {
            $seen[] = $t->live;

            return $page($t);
        }, ...$options);
    });

    return [$response, $seen];
}

test('a GET is a page: title, the root, one inline client, the request in the component', function () {
    [$response, $seen] = from_run(from_request('/n/7?x=1'), fn (Tether $t) => $t->mount(Greeting::class, ['who' => 'Ada'], 'Hello <you>'));
    $html = (string) $response->getBody();
    expect($response->getStatusCode())->toBe(200)
        ->and($response->getHeaderLine('Content-Type'))->toBe('text/html; charset=utf-8')
        ->and($seen)->toBe([false])
        ->and($html)->toContain('<title>Hello &lt;you&gt;</title>')
        ->toContain('<p tether-id="c1">Ada</p>')
        ->not->toContain('id="tether-mount"')
        ->and(substr_count($html, 'data-tether'))->toBe(1)
        ->and(strpos($html, 'var Idiomorph'))->toBeLessThan(strpos($html, "'use strict'"))
        ->and($GLOBALS['from_mounted'])->toBe(['/n/7']);
});

test('the page head goes in the default document, after the client', function () {
    [$response] = from_run(from_request('/'), fn (Tether $t) => new Page(Greeting::class, [], 'T', '<link rel="stylesheet" href="/x.css">'));
    $html = (string) $response->getBody();
    expect($html)->toContain('<link rel="stylesheet" href="/x.css">')
        ->and(strpos($html, '<link rel="stylesheet"'))->toBeGreaterThan(strpos($html, 'data-tether'));
});

test('a nonce goes on the inline script', function () {
    [$response] = from_run(from_request('/'), fn (Tether $t) => $t->mount(Greeting::class), ['nonce' => 'abc"123']);
    expect((string) $response->getBody())->toContain('<script type="module" data-tether nonce="abc&quot;123">');
});

test('a shell gets the root, the scripts and the page, and its string is the response', function () {
    $args = null;
    [$response] = from_run(from_request('/'), fn (Tether $t) => $t->mount(Greeting::class, ['who' => 'Ada'], 'Shelled'), ['shell' => function (string $root, string $scripts, Page $page) use (&$args) {
        $args = [$root, $scripts, $page];

        return "<html><head>$scripts</head><body><main>$root</main></body></html>";
    }]);
    expect($args[0])->toBe('<p tether-id="c1">Ada</p>')
        ->and($args[1])->toContain('<script type="module" data-tether')
        ->and($args[2]->title)->toBe('Shelled')
        ->and((string) $response->getBody())->toBe("<html><head>{$args[1]}</head><body><main>{$args[0]}</main></body></html>");
});

test('a shell that leaves out the scripts fails loudly', function () {
    expect(fn () => from_run(from_request('/'), fn (Tether $t) => $t->mount(Greeting::class), ['shell' => fn (string $root) => "<html>$root</html>"]))
        ->toThrow(LogicException::class);
});

test('a response from the closure goes out as it is; anything else is an error', function () {
    [$response] = from_run(from_request('/'), fn () => new Response(302, ['Location' => '/login'], ''));
    expect($response->getStatusCode())->toBe(302)->and($response->getHeaderLine('Location'))->toBe('/login');
    [$response] = from_run(from_request('/'), fn () => new Response(404, [], 'nope'));
    expect($response->getStatusCode())->toBe(404);
    expect(fn () => from_run(from_request('/'), fn () => 'a string'))->toThrow(LogicException::class);
});

test('only GET and HEAD: 405 with Allow, and the closure does not run', function () {
    foreach ([from_request('/', method: 'POST'), from_request('/', method: 'POST', upgrade: true)] as $request) {
        [$response, $seen] = from_run($request, fn (Tether $t) => $t->mount(Greeting::class));
        expect($response->getStatusCode())->toBe(405)->and($response->getHeaderLine('Allow'))->toBe('GET, HEAD')->and($seen)->toBe([]);
    }
});

test('an upgrade from another site is 403 before the closure runs', function () {
    [$response, $seen] = from_run(from_request('/', ['Origin' => 'https://evil.example'], upgrade: true), fn (Tether $t) => $t->mount(Greeting::class));
    expect($response->getStatusCode())->toBe(403)->and($seen)->toBe([]);
});

test('an upgrade from this host, with no Origin, or from a listed origin is accepted; the closure runs first, each time', function () {
    $accept = base64_encode(sha1('dGhlIHNhbXBsZSBub25jZQ==258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    foreach ([['Origin' => 'https://example.test'], [], ['Origin' => 'https://app.example.com']] as $headers) {
        [$response, $seen] = from_run(from_request('/', $headers, upgrade: true), fn (Tether $t) => $t->mount(Greeting::class), ['origins' => ['https://app.example.com']]);
        expect($response->getStatusCode())->toBe(101)
            ->and($response->getHeaderLine('Sec-WebSocket-Accept'))->toBe($accept)
            ->and($seen)->toBe([true]);
        phasync::run(fn () => from_frames($response));
    }
});

test('the live tab mounts what the closure returned, with its objects and the upgrade request', function () {
    $user = new class { public string $name = 'Grace'; };
    $GLOBALS['from_mounted'] = [];
    [$messages, $close] = phasync::run(function () use ($user) {
        $response = Tether::from(from_request('/n/9', upgrade: true), fn (Tether $t) => $t->mount(Greeting::class, ['who' => 'Hi ', 'user' => $user]));

        return from_frames($response);
    });
    expect($messages[0]['t'])->toBe('mount')
        ->and($messages[0]['html'])->toBe('<p tether-id="c1">Hi Grace</p>')
        ->and($close)->toBe(1001)
        ->and($GLOBALS['from_mounted'])->toBe(['/n/9']);
});

test('a page tells its version, and a live connection that names another is closed with 4001', function () {
    $page = fn (Tether $t) => $t->mount(Greeting::class);
    [$response] = from_run(from_request('/'), $page);
    $own = Live::version();
    expect((string) $response->getBody())->toContain('<meta name="tether-version" content="' . $own . '">');
    [$response] = from_run(from_request('/'), $page, ['version' => 'abc123']);
    expect((string) $response->getBody())->toContain('content="' . Live::version('abc123') . '"')->not->toContain($own);
    foreach ([['{"v":"' . $own . '"}', 'abc123', 4001], ['{"v":"' . Live::version('abc123') . '"}', 'abc123', 1001], ['{}', '', 4001], ['{"v":"' . $own . '"}', '', 1001]] as [$first, $version, $expected]) {
        [$response] = from_run(from_request('/', upgrade: true, frames: [$first]), $page, ['version' => $version]);
        [$messages, $close] = phasync::run(fn () => from_frames($response));
        expect($close)->toBe($expected)->and(count($messages) > 0)->toBe(1001 === $expected);
    }
});

test('an upgrade the closure answers with a redirect sends the page there; another response closes with 1008', function () {
    [$messages, $close] = phasync::run(fn () => from_frames(Tether::from(from_request('/', upgrade: true), fn () => new Response(302, ['Location' => '/login'], ''))));
    expect($messages)->toBe([['t' => 'frame', 'nav' => ['load' => '/login']]])->and($close)->toBe(1000);
    [$messages, $close] = phasync::run(fn () => from_frames(Tether::from(from_request('/', upgrade: true), fn () => new Response(404, [], 'nope'))));
    expect($messages)->toBe([])->and($close)->toBe(1008);
});

test('request() needs a tab that has one; the client files hold no script end tag', function () {
    $component = new Greeting();
    $component->attach(new Circuit(), 'c1');
    expect(fn () => $component->path())->toThrow(LogicException::class);
    foreach (['tether.js', 'idiomorph.min.js'] as $file) {
        expect(file_get_contents(__DIR__ . '/../resources/' . $file))->not->toContain('</script');
    }
});
