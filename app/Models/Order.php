<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $appends = [
        'status_label',
        'woo_status',
        'woo_status_label',
    ];

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'store_slug',
        'external_id',
        'status',
        'total',
        'currency',
        'customer_name',
        'error_reason',
        'delivery_image_path',
        'error_created_at',
        'meta',
        'synced_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
            'error_created_at' => 'datetime',
            'deleted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'meta' => 'array',
            'status' => OrderStatus::class,
        ];
    }

    /**
     * Get the user who last updated this order.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all timeline events for this order.
     */
    public function timelines(): HasMany
    {
        return $this->hasMany(OrderTimeline::class);
    }

    /**
     * Get all audit logs for this order.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(OrderLog::class)->latest();
    }

    /**
     * Check if order can transition to the given status.
     */
    public function canTransitionTo(OrderStatus $newStatus): bool
    {
        return $this->status->canTransitionTo($newStatus);
    }

    /**
     * Get the current status label.
     */
    public function getStatusLabel(): string
    {
        return $this->status->label();
    }

    protected function statusLabel(): Attribute
    {
        return Attribute::get(fn (): string => $this->status->label());
    }

    protected function wooStatus(): Attribute
    {
        return Attribute::get(function (): ?string {
            $rawStatus = data_get($this->meta, 'status');

            if (is_string($rawStatus) && $rawStatus !== '') {
                return strtolower($rawStatus);
            }

            return $this->mapInternalStatusToWoo($this->status);
        });
    }

    protected function wooStatusLabel(): Attribute
    {
        return Attribute::get(function (): string {
            return self::wooStatusLabels()[$this->woo_status] ?? $this->status->label();
        });
    }

    /**
     * @return array<string, string>
     */
    public static function wooStatusLabels(): array
    {
        return [
            'pending' => 'Pendiente de Pago',
            'processing' => 'En Proceso',
            'on-hold' => 'En Espera',
            'completed' => 'Completado',
            'cancelled' => 'Cancelado',
            'refunded' => 'Reembolsado',
            'failed' => 'Fallido',
        ];
    }

    private function mapInternalStatusToWoo(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::EN_PROCESO => 'processing',
            OrderStatus::EMPAQUETADO,
            OrderStatus::DESPACHADO,
            OrderStatus::EN_CAMINO => 'processing',
            OrderStatus::ENTREGADO => 'completed',
            OrderStatus::CANCELADO => 'cancelled',
            OrderStatus::ERROR => 'failed',
        };
    }

    /**
     * Resolve the model from the route binding.
     * Tries external_id first (from WooCommerce), then falls back to local id.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        // Try by external_id first (frontend sends WooCommerce order ID)
        $model = $this->where('external_id', $value)->first();
        
        if ($model) {
            return $model;
        }

        // Fall back to local id
        return $this->where('id', $value)->first();
    }
}
