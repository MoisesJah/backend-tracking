<?php

namespace App\Services;

use App\Services\Integrations\BsaleClient;

class BsaleService
{
    protected $client;

    public function __construct(BsaleClient $client)
    {
        $this->client = $client;
    }

    public function getOrders($offset = 0, $limit = 50)
    {
        // Llamamos al cliente de integración
        $response = $this->client->get('documents', [
            'limit' => $limit,
            'offset' => $offset,
            'expand' => '[client,sellers,attributes,payments,details]'
        ]);

        $data = $response->json();
        
        // Filtrar vendedores WEB y formatear (igual que la lógica anterior)
        $items = collect($data['items'] ?? [])->filter(function ($order) {
            $vendedor = $order['sellers']['items'][0] ?? null;
            if (!$vendedor) return true;
            
            $fullName = strtoupper(($vendedor['firstName'] ?? '') . ' ' . ($vendedor['lastName'] ?? ''));
            return !str_contains($fullName, 'WEB');
        })->map(function ($order) {
            return $this->formatOrder($order);
        })->values();

        return [
            'count' => $data['count'] ?? 0,
            'next' => $data['next'] ?? null,
            'items' => $items
        ];
    }

    private function formatOrder($order) {
        // ... (Aquí va la lógica de atributos y cálculos que ya armamos)
    }
}