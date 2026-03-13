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
    private int $perPage;

    public function __construct(private readonly array $storeConfig)
    {
        $baseUrl    = rtrim($storeConfig['base_url'], '/');
        $apiVersion = config('woocommerce.api_version', 'wc/v3');
        $this->perPage = (int) config('woocommerce.per_page', 100);
 
        $this->client = Http::withBasicAuth(
            $storeConfig['consumer_key'],
            $storeConfig['consumer_secret']
        )
            ->timeout(config('woocommerce.timeout', 30))
            ->acceptJson()
            ->baseUrl("{$baseUrl}/wp-json/{$apiVersion}");
    }
     public function getLabel(): string
    {
        return $this->storeConfig['label'] ?? 'unknown';
    }

    /**
     * GET a cualquier endpoint de WooCommerce.
     * 
     *
     */
     public function get(string $endpoint, array $params = []): array
    {
        return $this->handleResponse($this->client->get($endpoint, $params), "GET {$endpoint}");
    }

    /**
     * GET paginado: recorre TODAS las páginas y devuelve todos los registros.
     *
     */
     public function getAll(string $endpoint, array $params = [], ?int $maxPages = null): array
    {
        $allItems = [];
        $page     = 1;
        $limit    = $maxPages ?? (int) config('woocommerce.max_pages', 0);

        if ($limit < 0) {
            $limit = 0;
        }
 
        do {
            $response = $this->client->get($endpoint, array_merge($params, [
                'page'     => $page,
                'per_page' => $this->perPage,
            ]));
 
            $items = $this->handleResponse($response, "GET {$endpoint} (página {$page})");
 
            if (empty($items)) break;
 
            $allItems   = array_merge($allItems, $items);
            $totalPages = (int) $response->header('X-WP-TotalPages');
            $page++;
 
        } while ($page <= $totalPages && ($limit === 0 || $page <= $limit));
 
        return $allItems;
    }
    /**
     * GET de un recurso por ID.
     *
     */
     public function find(string $endpoint, int|string $id): array
    {
        return $this->handleResponse($this->client->get("{$endpoint}/{$id}"), "GET {$endpoint}/{$id}");
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
 
        $data = $this->handleResponse($response, "GET {$endpoint} paginado");
 
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
    
    #MANEJO DE ERRORES 
      private function handleResponse(Response $response, string $context): array
    {
        if ($response->successful()) {
            return $this->decodeResponseJson($response, $context);
        }
 
        $status  = $response->status();
        $body    = $this->decodeResponseJson($response, $context, false);
        $message = $body['message'] ?? $response->body();
 
        Log::error("[WooCommerceService:{$this->getLabel()}] Error en {$context}", [
            'status'  => $status,
            'message' => $message,
        ]);
 
        throw new RuntimeException(
            "[WooCommerce:{$this->getLabel()}] {$context} falló con status {$status}: {$message}",
            $status
        );
    }

    private function decodeResponseJson(Response $response, string $context, bool $throwOnFailure = true): array
    {
        $body = $response->body();

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        $sanitizedBody = $this->sanitizeJsonBody($body);
        $decoded = json_decode($sanitizedBody, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            Log::warning("[WooCommerceService:{$this->getLabel()}] Respuesta saneada para {$context}", [
                'status' => $response->status(),
            ]);

            return $decoded;
        }

        if (!$throwOnFailure) {
            return [];
        }

        $error = json_last_error_msg();

        Log::error("[WooCommerceService:{$this->getLabel()}] No se pudo decodificar la respuesta JSON de {$context}", [
            'status' => $response->status(),
            'error' => $error,
            'body_prefix' => substr($body, 0, 500),
        ]);

        throw new RuntimeException(
            "[WooCommerce:{$this->getLabel()}] {$context} devolvió un JSON inválido: {$error}"
        );
    }

    private function sanitizeJsonBody(string $body): string
    {
        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }

        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $body) ?? $body;
        $converted = iconv('UTF-8', 'UTF-8//IGNORE', $body);

        return $converted === false ? $body : $converted;
    }
}
