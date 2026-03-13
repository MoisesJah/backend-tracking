<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

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
}
