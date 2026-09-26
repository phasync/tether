<?php

// A closure; its array return value becomes JSON. Reads the request three ways.
return function () {
    return [
        'get'     => $_GET['id'] ?? null,
        'request' => mini\request()->getQueryParams()['id'] ?? null,
        'method'  => mini\request()->getMethod(),
    ];
};
