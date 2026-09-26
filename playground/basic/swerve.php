<?php

// Swerve hosts the application through mini's PSR-15 request pipeline
return mini\Mini::$mini->get(mini\Dispatcher\RequestDispatcher::class);
