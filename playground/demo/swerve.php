<?php

// The Tether demo: vendor/bin/swerve --http=8080 swerve.php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Http\Message\Response;
use Tether\Tether;

$app = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return match ($request->getUri()->getPath()) {
            '/'     => Tether::page(Demo\Page::class, ['title' => 'Tether demo'], 'Tether demo'),
            '/fast' => Tether::page(Demo\FastPage::class, ['rate' => max(1, min(1000, (int) ($request->getQueryParams()['rate'] ?? 50)))], 'Tether: fast'),
            default => new Response('Not found', [], 404),
        };
    }
};

// Tether's client and live connection first, then the application
return new class(new Tether(), $app) implements RequestHandlerInterface {
    public function __construct(private Tether $tether, private RequestHandlerInterface $app)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->tether->process($request, $this->app);
    }
};
