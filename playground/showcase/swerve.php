<?php

// Everything Tether can do on one page. No framework: a tiny router, Tether::from() for the page.
// vendor/bin/swerve --ext --public=public swerve.php
use phasync\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Showcase\Showcase;
use Tether\Tether;

return new class implements RequestHandlerInterface {
    private const HEAD = '<meta name="color-scheme" content="light dark"><link rel="stylesheet" href="/showcase.css"><script src="/showcase.js" defer></script>';

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return match ($request->getUri()->getPath()) {
            '/'          => Tether::from($request, fn (Tether $t) => $t->mount(Showcase::class, [], 'Tether showcase', self::HEAD)),
            '/api/hello' => new Response(200, ['Content-Type' => 'application/json'], json_encode(['from' => 'the server', 'pid' => getmypid(), 'time' => date('H:i:s')])),
            default      => new Response(404, [], 'Not found'),
        };
    }
};
