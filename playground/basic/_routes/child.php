<?php

// Test only: a coroutine the request starts shares its request scope
return function () {
    $seen = \phasync::await(\phasync::go(fn () => [$_GET['id'] ?? null, mini\request()->getQueryParams()['id'] ?? null]));

    return ['child' => $seen];
};
