<?php

declare(strict_types=1);

return [
    'secret' => env('JWT_SECRET'),
    'issuer' => env('JWT_ISSUER', env('APP_URL')),
    'audience' => env('JWT_AUDIENCE'),
    'ttl_seconds' => (int) env('JWT_TTL_SECONDS', 900),
    'leeway_seconds' => (int) env('JWT_LEEWAY_SECONDS', 0),
    'refresh_ttl_seconds' => (int) env('JWT_REFRESH_TTL_SECONDS', 60 * 60 * 24 * 30),
];

