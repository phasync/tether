<?php

namespace Demo;

use phasync\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Tether\App;
use Tether\Page;
use Tether\Route;

/** The multi-page demo, at /app/: rooms in one layout, an about page with its own root. */
final class ChatApp extends App
{
    #[Route('/')]
    public function home(): Page
    {
        return new Page(Shell::class, ['room' => null], 'Lobby');
    }

    #[Route('/rooms/{id}')]
    public function room(int $id): Page|ResponseInterface
    {
        if ($id > 3) {
            return new Response(404, ['Content-Type' => 'text/plain'], 'No such room');
        }

        return new Page(Shell::class, ['room' => $id], "Room $id");
    }

    /** An old address: redirects within the App are followed over the connection. */
    #[Route('/old-room')]
    public function oldRoom(): ResponseInterface
    {
        return new Response(302, ['Location' => '/app/rooms/2'], '');
    }

    #[Route('/about')]
    public function about(): Page
    {
        return new Page(About::class, [], 'About');
    }
}
