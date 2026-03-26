<?php

namespace App\Services\Integrations;

use InvalidArgumentException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class BsaleClient
{
    protected $baseUrl;
    protected $token;

    public function __construct()
    {
        $this->baseUrl = (string) Config::get('bsale.base_url', '');
        $this->token = (string) Config::get('bsale.token', '');
    }

    public function get(string $endpoint, array $params = [])
    {
        if (blank($this->baseUrl) || blank($this->token)) {
            throw new InvalidArgumentException('Falta configurar BSALE_BASE_URL o BSALE_TOKEN en el entorno.');
        }

        $path = trim($endpoint, '/');

        if (!str_ends_with($path, '.json')) {
            $path .= '.json';
        }

        return Http::withHeaders([
            'access_token' => $this->token,
            'Accept' => 'application/json',
        ])
            ->baseUrl(rtrim($this->baseUrl, '/'))
            ->timeout(15)
            ->connectTimeout(10)
            ->get('/' . $path, $params);
    }
}