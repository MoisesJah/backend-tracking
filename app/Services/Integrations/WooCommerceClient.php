<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

final class WooCommerceClient
{
    public function __construct(
        private readonly ClientInterface $http,
    ) {
    }

    public function get(string $path, array $query = []): ResponseInterface
    {
        return $this->request('GET', $path, [
            'query' => $this->authQueryParams() + $query,
        ]);
    }

    public function post(string $path, array $body = []): ResponseInterface
    {
        return $this->request('POST', $path, [
            RequestOptions::JSON => $body,
            'query' => $this->authQueryParams(),
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $method, string $path, array $options = []): ResponseInterface
    {
        $baseUrl = (string) config('woocommerce.base_url', '');

        return $this->http->request($method, rtrim($baseUrl, '/').'/'.ltrim($path, '/'), $options);
    }

    /**
     * @return array<string, string>
     */
    private function authQueryParams(): array
    {
        return [
            'consumer_key' => (string) config('woocommerce.consumer_key'),
            'consumer_secret' => (string) config('woocommerce.consumer_secret'),
        ];
    }
}

