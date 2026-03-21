<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class BsaleController extends Controller
{
    public function getOrderDetails($offset)
    {
        $apiKey = '11ae7efdd690e8a3f55e1fe75387437395f04de2';
        
        $response = Http::withHeaders([
            'access_token' => $apiKey,
            'Accept' => 'application/json',
        ])->get("https://api.bsale.cl/v1/documents.json", [
            'limit' => 1,
            'offset' => $offset,
            'expand' => '[office,client,details,sellers,attributes,payments]'
        ]);

        $doc = $response->json()['items'][0] ?? null;

        if (!$doc) {
            return response()->json(['error' => 'Documento no encontrado'], 404);
        }

        // Lógica de Atributos
        $getAttr = function($name) use ($doc) {
            $attr = collect($doc['attributes']['items'] ?? [])->first(fn($a) => trim(strtoupper($a['name'])) === strtoupper($name));
            if (!$attr) return "N/A";
            $value = $attr['value'] ?? "";
            if ($value !== "" && !is_numeric($value)) return $value;
            return $attr['details'][0]['name'] ?? $value;
        };

        // Procesar Prendas (Lógica matemática solicitada)
        $prendas = collect($doc['details']['items'])->map(function($item) use ($apiKey) {
            $varRes = Http::withHeaders(['access_token' => $apiKey])
                ->get("https://api.bsale.cl/v1/variants/{$item['variant']['id']}.json?expand=[product]");
            
            $varData = $varRes->json();
            $montoPagadoReal = $item['totalAmount'];
            $descuento = $item['totalDiscount'] ?? 0;

            return [
                'nombre' => $varData['product']['name'] ?? 'N/A',
                'talla' => $item['variant']['description'] ?? 'N/A',
                'sku' => $item['variant']['code'] ?? 'N/A',
                'cantidad' => $item['quantity'],
                'precioUnitario' => $montoPagadoReal + $descuento,
                'descuentoAplicado' => $descuento,
                'totalAPagar' => $montoPagadoReal
            ];
        });

        return response()->json([
            'boleta' => $doc['serialNumber'],
            'fechaEmision' => date('d/m/Y, h:i A', $doc['emissionDate'] ?? $doc['generationDate']),
            'cliente' => [
                'nombre' => trim(($doc['client']['firstName'] ?? '') . ' ' . ($doc['client']['lastName'] ?? '')),
                'dni_ruc' => $doc['client']['code'] ?? 'N/A',
                'email' => $doc['client']['email'] ?? 'No registrado',
                'telefono' => $doc['client']['phone'] ?? 'N/A'
            ],
            'vendedor' => trim(($doc['sellers']['items'][0]['firstName'] ?? '') . ' ' . ($doc['sellers']['items'][0]['lastName'] ?? '')),
            'atributos' => [
                'fechaDespacho' => $getAttr("FECHA DE DESPACHO"),
                'marcaRedSocial' => $getAttr("MARCA/RED SOCIAL"),
                'estadoPedido' => $getAttr("ESTADO DE PEDIDO")
            ],
            'pago' => [
                'metodos' => collect($doc['payments'] ?? [])->map(fn($p) => $p['name'] . " (S/ " . number_format($p['amount'], 2) . ")")->implode(' + '),
                'montoTotal' => "S/ " . number_format(collect($doc['payments'] ?? [])->sum('amount'), 2)
            ],
            'prendas' => $prendas
        ]);
    }
}