<?php

namespace App\Services;

use App\Services\Integrations\BsaleClient;

class BsaleService
{
    protected $client;
    // La caché estática es vital para que no se cuelgue por exceso de llamadas
    protected static $variantCache = [];

    public function __construct(BsaleClient $client)
    {
        $this->client = $client;
    }

    public function getOrders($offset = 0, $limit = 50)
    {
        $firstResponse = $this->client->get('documents', ['limit' => 1, 'state' => 0]);
        $total = $firstResponse->json()['count'] ?? 0;

        $realOffset = max(0, $total - $limit - $offset);

        $response = $this->client->get('documents', [
            'limit'   => $limit,
            'offset'  => $realOffset,
            'state'   => 0,
            'expand'  => '[client,sellers,attributes,payments,details]'
        ]);

        $data = $response->json();
        
        $items = collect($data['items'] ?? [])
            ->reverse() 
            ->filter(function ($order) {
                $vendedor = $order['sellers']['items'][0] ?? null;
                if (!$vendedor) return true;
                $fullName = strtoupper(($vendedor['firstName'] ?? '') . ' ' . ($vendedor['lastName'] ?? ''));
                return !str_contains($fullName, 'WEB');
            })
            ->map(function ($order) {
                return $this->formatOrder($order);
            })
            ->values();

        return [
            'total_registros' => $total,
            'items' => $items
        ];
    }

    private function formatOrder($order) 
    {
        $timestamp = $order['generationDate'] ?? ($order['emissionDate'] ?? time());

        $getAttr = function($name) use ($order) {
            $attributes = $order['attributes']['items'] ?? [];
            $attr = collect($attributes)->first(fn($a) => trim(strtoupper($a['name'] ?? '')) === strtoupper($name));
            if (!$attr) return "No encontrado";
            $value = $attr['value'] ?? "";
            return (is_numeric($value) && isset($attr['details'][0]['name'])) ? $attr['details'][0]['name'] : ($value ?: "No encontrado");
        };

        $pagos = collect($order['payments'] ?? []);
        $totalCaja = $pagos->sum('amount');
        
        $metodosDetallados = $pagos->map(function($p) {
            $nombreMetodo = $p['name'] ?? 'Pago';
            $monto = number_format($p['amount'], 2);
            return "{$nombreMetodo} (S/ {$monto})";
        })->implode(' + ');

        return [
            'boleta' => $order['serialNumber'] ?? "TK-{$order['number']}",
            'fechaEmision' => date('d/m/Y, h:i A', $timestamp),
            'cliente' => [
                'nombre' => trim(($order['client']['firstName'] ?? '') . ' ' . ($order['client']['lastName'] ?? '')) ?: "No encontrado",
                'dni_ruc' => $order['client']['code'] ?? 'No encontrado',
                'email' => $order['client']['email'] ?? 'No encontrado',
                'telefono' => $order['client']['phone'] ?? 'No encontrado'
            ],
            'vendedor' => trim(($order['sellers']['items'][0]['firstName'] ?? '') . ' ' . ($order['sellers']['items'][0]['lastName'] ?? '')) ?: "No encontrado",
            'atributos' => [
                'fechaDespacho' => $getAttr("FECHA DE DESPACHO"),
                'marcaRedSocial' => $getAttr("MARCA/RED SOCIAL"),
                'estadoPedido' => $getAttr("ESTADO DE PEDIDO"),
            ],
            'pago' => [
                'metodos' => $metodosDetallados ?: "No encontrado", 
                'montoTotal' => "S/ " . number_format($totalCaja, 2)
            ],
            'prendas' => collect($order['details']['items'] ?? [])->map(function($item) {
                $variantId = $item['variant']['id'] ?? null;
                
                // Buscamos el detalle de la variante (Nombre Producto - Talla)
                $infoProducto = $this->getVariantDetail($variantId);
                
                $montoPagadoReal = $item['totalAmount'];
                $descuento = $item['totalDiscount'] ?? 0;

                return [
                    'nombre' => $infoProducto['nombre'],
                    'sku' => $item['variant']['code'] ?? 'No encontrado',
                    'cantidad' => $item['quantity'],
                    'precioUnitario' => $montoPagadoReal + $descuento,
                    'descuentoAplicado' => $descuento,
                    'totalAPagar' => $montoPagadoReal
                ];
            })
        ];
    }

    private function getVariantDetail($variantId)
    {
        if (!$variantId) return ['nombre' => 'No encontrado'];

        // Si ya está en la memoria de esta carga, lo devolvemos inmediatamente
        if (isset(self::$variantCache[$variantId])) {
            return self::$variantCache[$variantId];
        }

        try {
            // Hacemos la llamada al endpoint de variantes
            $response = $this->client->get("variants/{$variantId}", ['expand' => '[product]']);
            
            if ($response->successful()) {
                $data = $response->json();
                $productName = $data['product']['name'] ?? 'Producto';
                $variantDesc = $data['description'] ?? '';
                $fullName = trim("$productName - $variantDesc");
                
                self::$variantCache[$variantId] = ['nombre' => $fullName ?: "No encontrado"];
                return self::$variantCache[$variantId];
            }
        } catch (\Exception $e) {
            // Error de conexión o timeout
        }

        return ['nombre' => "No encontrado"];
    }
}