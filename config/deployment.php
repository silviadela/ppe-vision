<?php

return [
    'build' => '2026.10.14.1',
    'infinityfree_install_token' => env('INFINITYFREE_INSTALL_TOKEN'),
    'allow_admin_writes' => env(
        'ALLOW_ADMIN_WRITES',
        env('APP_ENV', 'production') !== 'production'
    ),
];
