<?php

namespace App\Http\Controllers;

use App\Live\Chat;
use App\Live\Home;
use Illuminate\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Laravel\Handler;
use Tether\Page;
use Tether\Tether;

/** Each action is one Tether::from(): the same route answers the page and its live connection. */
final class LiveController extends Controller
{
    public function home(ServerRequestInterface $psr): ResponseInterface
    {
        return $this->page($psr, fn (Tether $t) => $t->mount(Home::class, ['heading' => 'Tether in Laravel'], 'Tether in Laravel'));
    }

    public function chat(ServerRequestInterface $psr, Request $request, string $room): ResponseInterface
    {
        $user = $request->query('name', 'guest');

        return $this->page($psr, fn (Tether $t) => $t->mount(Chat::class, ['room' => $room, 'user' => $user], "#$room"));
    }

    private function page(ServerRequestInterface $psr, \Closure $page): ResponseInterface
    {
        return Tether::from(
            $psr,
            $page,
            shell: fn (string $root, string $scripts, Page $page) => view('layouts.app', ['title' => $page->title, 'scripts' => $scripts, 'root' => $root])->render(),
            // The live tab runs after the request's application is released: it gets one of its own for as long as it is open
            enter: fn (ServerRequestInterface $request, \Closure $tab) => Handler::run($tab),
        );
    }
}
