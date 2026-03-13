<?php

declare(strict_types=1);

$stores   = [];
$slugList = env('WOO_STORES', '');
$slugs    = array_filter(array_map('trim', explode(',', $slugList)));
 
foreach ($slugs as $slug) {
    $prefix = 'WOO_' . strtoupper($slug) . '_';
 
    $stores[$slug] = [
        'base_url'        => env("{$prefix}BASE_URL"),
        'consumer_key'    => env("{$prefix}CONSUMER_KEY"),
        'consumer_secret' => env("{$prefix}CONSUMER_SECRET"),
        'label'           => env("{$prefix}LABEL", $slug),
    ];
}
 
return [
 
    'stores'      => $stores,
    'api_version' => env('WOO_API_VERSION', 'wc/v3'),
    'timeout'     => (int) env('WOO_TIMEOUT', 30),
    'per_page'    => (int) env('WOO_PER_PAGE', 100),
    'max_pages'   => (int) env('WOO_MAX_PAGES', 0),
 
];

