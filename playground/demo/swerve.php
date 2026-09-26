<?php

// The Tether demo, a mini application: vendor/bin/swerve --http=8080 --public=html swerve.php
// Tether's middleware serves its client and the live connections; mini's router the rest. Each
// live tab is its request's work (RequestDispatcher::within): request(), $_SESSION and the
// Scoped services are the tab's in its components.
use mini\Dispatcher\RequestDispatcher;
use mini\Mini;
use Tether\Tether;

$dispatcher = Mini::$mini->get(RequestDispatcher::class);
$dispatcher->addMiddleware(new Tether(enter: RequestDispatcher::within(...)));

return $dispatcher;
