<?php

use Tether\Tether;

return Tether::page(Demo\Page::class, ['title' => 'Tether demo'], 'Tether demo', <<<'HTML'
    <script src="/demo.js" defer></script>
    <style>
      html[tether-offline] body::before { content: 'Reconnecting…'; position: fixed; top: 0; left: 0; right: 0; padding: .3rem; background: #fd6; text-align: center; font: 14px system-ui }
    </style>
    HTML);
