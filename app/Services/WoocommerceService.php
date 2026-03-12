<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WoocommerceService
{
    private PendingRequest $client;
    private string $baseEndpoint;
    private int $perPage;

    public function __construct()
    {
       $baseUrl    = rtrim(config('woocommerce.base_url'), '/');
        $apiVersion = config('woocommerce.api_version', 'wc/v3');

        $this->baseEndpoint = "{$baseUrl}/wp-json/{$apiVersion}";
        $this->perPage      = (int) config('woocommerce.per_page', 100);

        $this->client = Http::withBasicAuth(
            config('woocommerce.consumer_key'),
            config('woocommerce.consumer_secret')
        )
            ->timeout(config('woocommerce.timeout', 30))
            ->acceptJson()
            ->baseUrl($this->baseEndpoint);
    }

    /**
     * GET a cualquier endpoint de WooCommerce.
     * 
     *
     */
    public function get(string $endpoint, array $params = []): array
    {
        $response = $this->client->get($endpoint, $params);

        return $this->handleResponse($response, "GET {$endpoint}");
    }

    /**
     * GET paginado: recorre TODAS las páginas y devuelve todos los registros.
     *
     */
    public function getAll(string $endpoint, array $params = []): array
    {
        $allItems   = [];
        $page       = 1;
        $perPage    = $this->perPage;

        do {
            $response = $this->client->get($endpoint, array_merge($params, [
                'page'     => $page,
                'per_page' => $perPage,
            ]));

            $this->handleResponse($response, "GET {$endpoint} (página {$page})");

            $body = $response->body();
            if (substr($body, 0, 3) === "\xef\xbb\xbf") {
                $body = substr($body, 3);
            }
            $items = json_decode($body, true) ?? [];

            if (empty($items)) {
                break;
            }

            $allItems = array_merge($allItems, $items);

            $totalPages = (int) $response->header('X-WP-TotalPages');
            $page++;

            // Small delay between pages to avoid overwhelming the remote server
            if ($page <= $totalPages) {
                usleep(200_000); // 200ms
            }

        } while ($page <= $totalPages);

        return $allItems;
    }
    /**
     * GET de un recurso por ID.
     *
     */
    public function find(string $endpoint, int|string $id): array
    {
        $response = $this->client->get("{$endpoint}/{$id}");

        return $this->handleResponse($response, "GET {$endpoint}/{$id}");
    }

    /**
     * GET paginado devolviendo metadata de paginación junto con los datos.
     *
     */
    public function getPaginated(string $endpoint, int $page = 1, int $perPage = 20, array $params = []): array
    {
        $response = $this->client->get($endpoint, array_merge($params, [
            'page'     => $page,
            'per_page' => $perPage,
        ]));

        $this->handleResponse($response, "GET {$endpoint} paginado");

        // Remove BOM from body if present and decode
        $body = $response->body();
        if (substr($body, 0, 3) === "\xef\xbb\xbf") {
            $body = substr($body, 3);
        }
        
        $data = json_decode($body, true);

        return [
            'data' => $data,
            'meta' => [
                'total'        => (int) $response->header('X-WP-Total'),
                'total_pages'  => (int) $response->header('X-WP-TotalPages'),
                'current_page' => $page,
                'per_page'     => $perPage,
            ],
        ];
    }
    /**
     * MANEJO DE RESPUESTAS Y ERRORES
     */
    private function handleResponse(Response $response, string $context): array
    {
        if ($response->successful()) {
            // Remove BOM from response if present
            $body = $response->body();
            if (substr($body, 0, 3) === "\xef\xbb\xbf") {
                $body = substr($body, 3);
            }
            
            $json = json_decode($body, true);
            return $json ?? [];
        }

        $status  = $response->status();
        $body    = $response->json();
        $message = $body['message'] ?? $response->body();

        Log::error("[WooCommerceService] Error en {$context}", [
            'status'  => $status,
            'message' => $message,
            'body'    => $body,
        ]);

        throw new RuntimeException(
            "[WooCommerce] {$context} falló con status {$status}: {$message}",
            $status
        );
    }
}
