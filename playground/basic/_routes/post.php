<?php

return fn () => ['post' => $_POST['text'] ?? null, 'body' => (string) mini\request()->getBody()];
