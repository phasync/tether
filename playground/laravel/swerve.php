<?php

// vendor/bin/swerve --http=127.0.0.1:8080 --public=public swerve.php   (add --ext with phasync-ext)
require __DIR__ . '/vendor/autoload.php';

return new Swerve\Laravel\Handler(__DIR__);
