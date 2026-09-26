<?php

use Tether\Tether;

return Tether::page(Demo\FastPage::class, ['rate' => max(1, min(1000, (int) ($_GET['rate'] ?? 50)))], 'Tether: fast');
