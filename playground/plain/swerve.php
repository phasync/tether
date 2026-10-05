<?php

// Tether with no framework: a PSR-15 handler with a tiny router; each live page is one Tether::from() call
// vendor/bin/swerve --ext swerve.php
use phasync\Psr\Response;
use Plain\Boost;
use Plain\Chat;
use Plain\Counter;
use Plain\Form;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tether\Tether;

return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ('/' === $path) {
            return Tether::from($request, fn (Tether $t) => $t->mount(Counter::class, ['count' => (int) ($request->getQueryParams()['start'] ?? 0)], 'Counter'));
        }
        if (preg_match('#^/boost/([ab])$#', $path, $page)) {
            return Tether::from($request, fn (Tether $t) => $t->mount(Boost::class, ['page' => $page[1]], "Boost {$page[1]}"));
        }
        if ('/plain.html' === $path) {
            return new Response(200, ['Content-Type' => 'text/html'], '<!doctype html><title>Plain</title><p id="plain">Not a Tether page</p>');
        }
        if ('/form' === $path) {
            return Tether::from($request, fn (Tether $t) => $t->mount(Form::class, [], 'Form'));
        }
        if (preg_match('#^/chat/(\w+)$#', $path, $room)) {
            $user = $request->getCookieParams()['user'] ?? '';

            return Tether::from($request, fn (Tether $t) => match (true) {
                '' === $user                         => new Response(302, ['Location' => '/login'], ''),
                !in_array($room[1], ['lobby', 'dev']) => new Response(404, [], 'No such room'),
                default                              => $t->mount(Chat::class, ['room' => $room[1], 'user' => $user], "#{$room[1]}"),
            });
        }
        if ('/login' === $path) {
            $name = trim($request->getQueryParams()['name'] ?? '');

            return '' === $name
                ? new Response(200, ['Content-Type' => 'text/html'], '<form><input name="name" placeholder="Your name"> <button>Sign in</button></form>')
                : new Response(302, ['Location' => '/chat/lobby', 'Set-Cookie' => 'user=' . rawurlencode($name) . '; Path=/'], '');
        }

        return new Response(404, [], 'Not found');
    }
};
