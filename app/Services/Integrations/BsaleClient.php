<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class BsaleClient
{
    protected $baseUrl;
    protected $token;

    public function __construct()
    {
        $this->baseUrl = Config::get('bsale.base_url');
        $this->token = Config::get('bsale.token');
    }

    public function get(string $endpoint, array $params = [])
    {
        return Http::withHeaders([
            'access_token' => $this->token,
            'Accept' => 'application/json',
        ])->get("{$this->baseUrl}/{$endpoint}.json", $params);
    }
}