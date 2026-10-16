<?php

return [
    'build' => '2026.10.16.1',
    'infinityfree_install_token' => env('INFINITYFREE_INSTALL_TOKEN'),
    'allow_admin_writes' => env('APP_ENV', 'production') !== 'production'
        && env('ALLOW_ADMIN_WRITES', true),
];
