<?php

use Tether\Tether;

return Tether::page(Demo\Events::class, [], 'Events', <<<'HTML'
    <style>
      #events > * { margin: 4px; padding: 4px; border: 1px solid #999; font: 14px system-ui }
      #pad { width: 200px; height: 80px } #hover, #intent, #held, #touch { width: 200px; height: 30px } #drag, #target { width: 100px; height: 30px }
      #seen { height: 60px; overflow: auto; margin: 0 }
    </style>
    HTML);
