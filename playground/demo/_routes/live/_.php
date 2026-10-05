<?php

use mini\Dispatcher\RequestDispatcher;
use Tether\Tether;

// /live/{id}: the page and its live connection are this one route. The closure runs for both, so
// $_GET[0] is the id live too; enter makes each tab the work of its request ($_SESSION, scoped services)
$id = $_GET[0];

return Tether::from(
    mini\request(),
    fn (Tether $t) => $t->mount(Demo\Page::class, ['title' => "Visit $id"], "Visit $id", '<script src="/demo.js" defer></script>'),
    enter: RequestDispatcher::within(...),
);
