<?php

namespace Demo;

use Tether\Component;

/** The layout of every room: the call survives moving between rooms; the room is keyed by id. */
final class Shell extends Component
{
    public ?int $room = null;

    public function goToRoom3(): void
    {
        $this->navigate('/app/rooms/3');
    }

    public function render(): string
    {
        $links = '<a href="/app/">Lobby</a>';
        foreach ([1, 2, 3] as $id) {
            $links .= " <a href=\"/app/rooms/$id\">Room $id</a>";
        }
        $body = null === $this->room ? '<p id="lobby">Pick a room</p>' : $this->child(Room::class, ['id' => $this->room], key: (string) $this->room);

        return <<<HTML
            <main style="font: 16px system-ui; max-width: 36rem; margin: 2rem auto">
              <nav id="links">{$links} <a href="/app/old-room">Old room</a> <a href="/app/about">About</a> <a href="/app/rooms/9">Room 9</a> <a href="/">Demo</a></nav>
              {$this->child(Call::class, key: 'call')}
              {$body}
              <button tether-click="goToRoom3" id="to-room-3">Go to room 3 from the server</button>
            </main>
            HTML;
    }
}
