<?php

use mini\Http\Message\HtmlResponse;

// A template with a layout
return new HtmlResponse(mini\render('home.php', ['name' => $_GET['name'] ?? 'World']));
