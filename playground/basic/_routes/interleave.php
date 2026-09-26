<?php

// Test only: reads the request, waits so that other requests run meanwhile, reads it again.
// phasync::sleep() is here to force coroutines to interleave; a mini application never calls it.
return function () {
    $before = [$_GET['id'] ?? null, mini\request()->getQueryParams()['id'] ?? null, $_COOKIE['c'] ?? null];
    \phasync::sleep(0.05 + mt_rand(0, 50) / 1000);
    $after = [$_GET['id'] ?? null, mini\request()->getQueryParams()['id'] ?? null, $_COOKIE['c'] ?? null];

    return ['before' => $before, 'after' => $after];
};
