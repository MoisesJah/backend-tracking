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
        // 1. Llamada a Bsale con todos los expand necesarios
        $response = $this->client->get('documents', [
            'limit' => $limit,
            'offset' => $offset,
            'state' => 0, // Solo documentos activos
            'sorting' => 'emissionDate:desc', // lo mas neuvo primero 
            'expand' => '[client,sellers,attributes,payments,details]'
        ]);

        $data = $response->json();
        
        // 2. Filtrar vendedores WEB y formatear
        $items = collect($data['items'] ?? [])
            ->filter(function ($order) {
                $vendedor = $order['sellers']['items'][0] ?? null;
                if (!$vendedor) return true;
                
                $fullName = strtoupper(($vendedor['firstName'] ?? '') . ' ' . ($vendedor['lastName'] ?? ''));
                // Si el nombre contiene WEB, lo sacamos de la lista
                return !str_contains($fullName, 'WEB');
            })
            ->map(function ($order) {
                // AQUÍ LLAMAMOS A LA FUNCIÓN QUE FORMATEA TODO
                return $this->formatOrder($order);
            })
            ->values(); // Reindexamos para evitar los "null" en el JSON

        return [
            'count' => $data['count'] ?? 0,
            'next' => $data['next'] ?? null,
            'items' => $items
        ];
    }

    /**
     * Esta función recupera los datos que se habían "perdido"
     */
    private function formatOrder($order) 
    {
        // --- LÓGICA DE ATRIBUTOS (Marca, Despacho, Estado) ---
        $getAttr = function($name) use ($order) {
            $attributes = $order['attributes']['items'] ?? [];
            $attr = collect($attributes)->first(function($a) use ($name) {
                return trim(strtoupper($a['name'])) === strtoupper($name);
            });

            if (!$attr) return "N/A";

            $value = $attr['value'] ?? "";
            // Si el valor no es un número, es texto directo (como la fecha de despacho)
            $valueEsNumero = is_numeric($value) && trim($value) !== "";

            if ($valueEsNumero && isset($attr['details']) && count($attr['details']) > 0) {
                return $attr['details'][0]['name']; // Trae "EQUIPO 3", "PEDIDO", etc.
            }

            return $value ?: "N/A";
        };

        // --- LÓGICA DE PAGOS ---
        $pagos = collect($order['payments'] ?? []);
        $totalCaja = $pagos->sum('amount');
        $metodosDetallados = $pagos->map(function($p) {
            return $p['name'] . " (S/ " . number_format($p['amount'], 2) . ")";
        })->implode(' + ');

        return [
            'boleta' => $order['serialNumber'] ?? "TK-{$order['number']}",
            'fechaEmision' => date('d/m/Y, h:i A', $order['emissionDate'] ?? $order['generationDate']),
            'cliente' => [
                'nombre' => trim(($order['client']['firstName'] ?? '') . ' ' . ($order['client']['lastName'] ?? '')),
                'dni_ruc' => $order['client']['code'] ?? 'N/A',
                'email' => $order['client']['email'] ?? 'No registrado',
                'telefono' => $order['client']['phone'] ?? 'N/A'
            ],
            'vendedor' => trim(($order['sellers']['items'][0]['firstName'] ?? '') . ' ' . ($order['sellers']['items'][0]['lastName'] ?? '')),
            
            // --- AQUÍ ESTÁ LO QUE FALTABA ---
            'atributos' => [
                'fechaDespacho' => $getAttr("FECHA DE DESPACHO"),
                'marcaRedSocial' => $getAttr("MARCA/RED SOCIAL"),
                'estadoPedido' => $getAttr("ESTADO DE PEDIDO"),
            ],
            
            'pago' => [
                'metodos' => $metodosDetallados ?: "EFECTIVO",
                'montoTotal' => "S/ " . number_format($totalCaja, 2)
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