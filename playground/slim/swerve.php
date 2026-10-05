<?php

// Tether in Slim: vendor/bin/swerve --ext swerve.php
// Each live page is one Tether::from() call in a route; the middleware's request attributes are there on the live tab too.
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use SlimDemo\Chat;
use SlimDemo\Counter;
use SlimDemo\Home;
use SlimDemo\Keys;
use Tether\Tether;

$app = AppFactory::create();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(false, true, false);

$app->add(static fn (ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface => $next->handle(
    $request->withAttribute('user', $request->getCookieParams()['user'] ?? 'guest')
));

$app->get('/', static fn (ServerRequestInterface $request) => Tether::from($request, fn (Tether $t) => $t->mount(Home::class, [], 'Home')));
$app->get('/counter', static fn (ServerRequestInterface $request) => Tether::from($request, fn (Tether $t) => $t->mount(Counter::class, [], 'Counter')));
$app->get('/keys', static fn (ServerRequestInterface $request) => Tether::from($request, fn (Tether $t) => $t->mount(Keys::class, [], 'Keys')));
$app->get('/chat/{room:lobby|dev}', static fn (ServerRequestInterface $request, ResponseInterface $response, array $args) => Tether::from(
    $request,
    fn (Tether $t) => $t->mount(Chat::class, ['room' => $args['room'], 'user' => $request->getAttribute('user')], "#{$args['room']}")
));

return $app;
