<?php

return [

    'paths' => ['api/*', 'broadcasting/auth', 'storage/*'], // add broadcasting/auth

    'allowed_methods' => ['*'],

    'allowed_origins' => ['http://localhost:8080'], // your Vue dev server

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true, // must be true for auth requests

];
