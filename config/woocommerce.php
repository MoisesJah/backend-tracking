<?php

declare(strict_types=1);

return [
    'base_url' => rtrim((string) env('WOO_BASE_URL', ''), '/'),
    'consumer_key' => env('WOO_CONSUMER_KEY'),
    'consumer_secret' => env('WOO_CONSUMER_SECRET'),
    'webhook_secret' => env('WOO_WEBHOOK_SECRET'),
    'timeout_seconds' => (int) env('WOO_TIMEOUT_SECONDS', 15),
];

