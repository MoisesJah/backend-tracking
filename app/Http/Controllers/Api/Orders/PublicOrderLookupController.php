<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\PublicOrderLookupRequest;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class PublicOrderLookupController extends Controller
{
    public function lookup(PublicOrderLookupRequest $request): JsonResponse
    {
        $boletaRaw = $request->boleta();
        $dniRaw = $request->dni();

        $boleta = $this->normalize($boletaRaw);
        $boletaDigits = $this->onlyDigits($boletaRaw);
        $dni = $this->onlyDigits($dniRaw);

        $candidates = Order::query()
            ->where(function (Builder $query) use ($boleta, $boletaDigits): void {
                $query->where('numero', $boleta)
                    ->orWhere('serie', $boleta);

                if ($boletaDigits !== '') {
                    $query->orWhere('numero', $boletaDigits)
                        ->orWhere('external_id', $boletaDigits)
                        ->orWhereRaw("(meta->'bsale'->>'boleta_id') = ?", [$boletaDigits]);
                }
            })
            ->latest('id')
            ->limit(30)
            ->get();

        $order = $candidates->first(function (Order $item) use ($dni): bool {
            $orderDni = $this->onlyDigits($this->extractDni($item->meta ?? []));

            return $orderDni !== '' && $orderDni === $dni;
        });

        if ($order === null) {
            return response()->json([
                'message' => 'No se encontró un pedido con la boleta y DNI ingresados.',
            ], 404);
        }

        $payload = $this->buildReadOnlyPayload($order);

        return response()->json([
            'message' => 'Pedido encontrado.',
            'order' => $payload,
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function extractDni(array $meta): string
    {
        $metaData = $meta['meta_data'] ?? [];

        if (is_array($metaData)) {
            foreach ($metaData as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $key = (string) ($row['key'] ?? '');
                if (in_array($key, ['dni_ce', '_billing_documento', 'billing_documento', 'dni'], true)) {
                    return (string) ($row['value'] ?? '');
                }
            }
        }

        return (string) data_get($meta, 'billing.dni', '');
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function buildReadOnlyPayload(Order $order): array
    {
        /** @var array<string, mixed> $meta */
        $meta = is_array($order->meta) ? $order->meta : [];
        $bsale = is_array($meta['bsale'] ?? null) ? $meta['bsale'] : [];

        $lineItems = $meta['line_items'] ?? [];
        $products = [];

        if (is_array($lineItems)) {
            foreach ($lineItems as $line) {
                if (! is_array($line)) {
                    continue;
                }

                $qty = (int) ($line['quantity'] ?? 0);
                $total = (float) ($line['total'] ?? 0);
                $price = isset($line['price']) ? (float) $line['price'] : ($qty > 0 ? $total / $qty : 0.0);

                $products[] = [
                    'name' => (string) ($line['name'] ?? ''),
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'subtotal' => $total,
                ];
            }
        }

        $deliveryAddress = $this->metaDataValue($meta, ['_billing_direccion_mapa', 'billing_direccion_mapa']);
        $lat = $this->metaDataValue($meta, ['_billing_cordenada_latitud', 'billing_cordenada_latitud']);
        $lng = $this->metaDataValue($meta, ['_billing_cordenada_longitud', 'billing_cordenada_longitud']);

        return [
            'id' => $order->id,
            'external_id' => $order->external_id,
            'store_slug' => $order->store_slug,
            'status' => $order->status?->value,
            'status_label' => $order->status_label,
            'woo_status' => $order->woo_status,
            'woo_status_label' => $order->woo_status_label,
            'total' => (float) $order->total,
            'currency' => (string) $order->currency,
            'created_at' => optional($order->created_at)->toIso8601String(),
            'bsale' => [
                'boleta_id' => $bsale['boleta_id'] ?? null,
                'numero' => $order->numero,
                'serie' => $order->serie,
            ],
            'customer' => [
                'name' => $order->customer_name ?: trim(((string) data_get($meta, 'billing.first_name', '')) . ' ' . ((string) data_get($meta, 'billing.last_name', ''))),
                'dni' => $this->extractDni($meta),
                'email' => (string) data_get($meta, 'billing.email', ''),
                'phone' => (string) data_get($meta, 'billing.phone', ''),
            ],
            'delivery' => [
                'address' => $deliveryAddress,
                'coordinates' => [
                    'lat' => $lat,
                    'lng' => $lng,
                ],
                'estimated_date_from' => $this->metaDataValue($meta, ['_billing_fecha_entrega_1', 'billing_fecha_entrega_1']),
                'estimated_date_to' => $this->metaDataValue($meta, ['_billing_fecha_entrega_2', 'billing_fecha_entrega_2']),
                'real_delivery_date' => (string) (data_get($meta, 'date_completed_gmt') ?? data_get($meta, 'date_completed') ?? ''),
            ],
            'products' => $products,
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @param list<string> $keys
     */
    private function metaDataValue(array $meta, array $keys): ?string
    {
        $metaData = $meta['meta_data'] ?? [];

        if (! is_array($metaData)) {
            return null;
        }

        foreach ($metaData as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = (string) ($row['key'] ?? '');
            if (in_array($key, $keys, true)) {
                $value = trim((string) ($row['value'] ?? ''));

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    private function normalize(string $value): string
    {
        return strtoupper(trim($value));
    }

    private function onlyDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
