<?php

// The Tether demo, a mini application: vendor/bin/swerve --http=8080 --public=html swerve.php
// Tether's middleware serves its client and the live connections; mini's router the rest.
use mini\Dispatcher\RequestDispatcher;
use mini\Mini;
use Tether\Tether;

$dispatcher = Mini::$mini->get(RequestDispatcher::class);
$dispatcher->addMiddleware(new Tether());

return $dispatcher;
