<?php

return [
    'name' => 'ASAP',
    'active_key_version' => env('ASAP_ACTIVE_KEY_VERSION', 2),
    'keys' => [
        1 => env('ASAP_SIGNING_KEY_V1', 'default_asap_signing_key_secret_12345_v1'),
        2 => env('ASAP_SIGNING_KEY_V2', 'default_asap_signing_key_secret_12345_v2'),
    ],
];
