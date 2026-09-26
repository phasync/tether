<?php

use mini\Http\Message\Response;

// Sign in by name, for the demo: the session is what the components see, live too
$_SESSION['name'] = substr(trim((string) ($_GET['name'] ?? '')), 0, 40);

return new Response('', ['Location' => '/'], 302);
