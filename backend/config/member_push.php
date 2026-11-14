<?php

return [
    'enabled' => env('REMIT_PUSH_ENABLED', false),
    'url' => env('REMIT_PUSH_BRIDGE_URL'),
    'secret' => env('REMIT_PUSH_BRIDGE_SECRET'),
];
