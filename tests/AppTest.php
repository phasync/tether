<?php

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Http\Message\Response;
use Swerve\Http\Message\ServerRequest;
use Tether\App;
use Tether\Component;
use Tether\Page;
use Tether\Route;

final class Hello extends Component
{
    public string $text = '';

    public function render(): string
    {
        return '<p>' . htmlspecialchars($this->text) . '</p>';
    }
}

final class TestApp extends App
{
    #[Route('/')]
    public function home(): Page
    {
        return new Page(Hello::class, ['text' => 'home'], 'Home');
    }

    #[Route('/items/{id}')]
    public function item(int $id, ServerRequestInterface $request): Page
    {
        return new Page(Hello::class, ['text' => "item $id " . ($request->getQueryParams()['tab'] ?? '')], "Item $id");
    }

    #[Route('/items/{name}')]
    public function named(string $name): Page
    {
        return new Page(Hello::class, ['text' => "named $name"]);
    }

    #[Route('/elsewhere')]
    public function elsewhere(): ResponseInterface
    {
        return new Response('', ['Location' => '/'], 302);
    }
}

/** $target through the App, mounted at $mount (the framework strips it from the target, as mini does). */
function app_get(string $target, string $mount = '', string $method = 'GET'): ResponseInterface
{
    return (new TestApp())->handle((new ServerRequest($method, "$mount$target", "", ["Host" => "example.test"]))->withRequestTarget($target));
}

test('a route\'s Page is a page, with the title, and the client below the App', function () {
    $html = (string) app_get('/items/7?tab=x', '/shop')->getBody();
    expect($html)->toContain('<title>Item 7</title>')
        ->toContain('<p tether-id="c1">item 7 x</p>')
        ->toContain('src="/shop/.tether/tether.js"')
        ->toContain('{"live":"\/shop\/.tether\/live","base":"\/shop"}');
});

test('parameters take the method\'s types: a segment that is not an int goes to the next route', function () {
    expect((string) app_get('/items/abc')->getBody())->toContain('named abc');
    expect((string) app_get('/items/-3')->getBody())->toContain('item -3');
});

test('other responses go out as they are; no route is 404; only GET and HEAD', function () {
    expect(app_get('/elsewhere')->getStatusCode())->toBe(302);
    expect(app_get('/nope')->getStatusCode())->toBe(404);
    expect(app_get('/', method: 'POST')->getStatusCode())->toBe(405);
    expect(app_get('/.tether/tether.js')->getHeaderLine('Content-Type'))->toStartWith('text/javascript');
});
