<?php

use Tether\Tether;

return Tether::page(Demo\InteropPage::class, [], 'Interop', <<<'HTML'
    <script src="/interop.js" defer></script>
    HTML);
