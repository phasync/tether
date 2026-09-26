<?php

// Under a classic SAPI (php -S, PHP-FPM) the pages render, without live connections
require __DIR__ . '/../vendor/autoload.php';
mini\dispatch();
