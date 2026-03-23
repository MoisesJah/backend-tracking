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
        $response = $this->client->get('documents', [
            'limit' => $limit,
            'offset' => $offset,
            'expand' => '[client,sellers,attributes,payments,details]'
        ]);

        $data = $response->json();
        
        // 1. Convertimos a colección
        $items = collect($data['items'] ?? [])
            // 2. FILTRAMOS primero: Si es WEB, se va de la lista de una vez
            ->filter(function ($order) {
                $vendedor = $order['sellers']['items'][0] ?? null;
                if (!$vendedor) return true; // Si no hay vendedor, lo dejamos por si acaso
                
                $fullName = strtoupper(($vendedor['firstName'] ?? '') . ' ' . ($vendedor['lastName'] ?? ''));
                
                // Retorna true si NO contiene WEB (es decir, lo mantiene)
                return !str_contains($fullName, 'WEB');
            })
            // 3. FORMATEAMOS solo lo que sobrevivió al filtro
            ->map(function ($order) {
                return $this->formatOrder($order);
            })
            // 4. REINDEXAMOS para que no queden huecos de IDs en el JSON
            ->values(); 

        return [
            'count' => $data['count'] ?? 0,
            'next' => $data['next'] ?? null,
            'items' => $items
        ];
    }

    private function formatOrder($order) 
    {
        $getAttr = function($name) use ($order) {
            $attr = collect($order['attributes']['items'] ?? [])
                ->first(fn($a) => trim(strtoupper($a['name'])) === strtoupper($name));
            if (!$attr) return "N/A";
            $value = $attr['value'] ?? "";
            if ($value !== "" && !is_numeric($value)) return $value;
            return $attr['details'][0]['name'] ?? $value;
        };

        $pagos = collect($order['payments'] ?? []);

        return [
            'boleta' => $order['serialNumber'],
            'fechaEmision' => date('d/m/Y, h:i A', $order['emissionDate'] ?? $order['generationDate']),
            'cliente' => [
                'nombre' => trim(($order['client']['firstName'] ?? '') . ' ' . ($order['client']['lastName'] ?? '')),
                'dni_ruc' => $order['client']['code'] ?? 'N/A',
                'email' => $order['client']['email'] ?? 'N/A',
                'telefono' => $order['client']['phone'] ?? 'N/A'
            ],
            'vendedor' => trim(($order['sellers']['items'][0]['firstName'] ?? '') . ' ' . ($order['sellers']['items'][0]['lastName'] ?? '')),
            'atributos' => [
                'fechaDespacho' => $getAttr("FECHA DE DESPACHO"),
                'marcaRedSocial' => $getAttr("MARCA/RED SOCIAL"),
                'estadoPedido' => $getAttr("ESTADO DE PEDIDO"),
            ],
            'pago' => [
                'metodos' => $pagos->map(fn($p) => $p['name'] . " (S/ " . number_format($p['amount'], 2) . ")")->implode(' + '),
                'montoTotal' => "S/ " . number_format($pagos->sum('amount'), 2)
            ],
            'prendas' => collect($order['details']['items'] ?? [])->map(function($item) {
                $montoPagadoReal = $item['totalAmount'];
                $descuento = $item['totalDiscount'] ?? 0;
                return [
                    'nombre' => $item['variant']['description'] ?? 'Producto',
                    'sku' => $item['variant']['code'] ?? 'N/A',
                    'cantidad' => $item['quantity'],
                    'precioUnitario' => $montoPagadoReal + $descuento,
                    'descuentoAplicado' => $descuento,
                    'totalAPagar' => $montoPagadoReal
                ];
            })
        ];
    }
}