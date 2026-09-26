<?php

return function () {
    $_SESSION['count'] = ($_SESSION['count'] ?? 0) + 1;

    return ['count' => $_SESSION['count']];
};
