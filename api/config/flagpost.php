<?php

declare(strict_types=1);

return [
    'admin_key' => env('FLAGPOST_ADMIN_KEY', ''),

    'schema_version' => 1,

    'environments' => ['dev', 'staging', 'prod'],

    'limits' => [
        'flags' => 500,
        'allow_list_entries' => 1000,
        'allow_list_entry_bytes' => 256,
        'allow_list_budget_bytes' => 1_048_576,
        'body_bytes' => 1_048_576,
        'description_characters' => 500,
    ],

    'rate_limits' => [
        'sdk_per_minute' => 120,
        'management_per_minute' => 60,
    ],
];
