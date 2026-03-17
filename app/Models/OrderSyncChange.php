<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSyncChange extends Model
{
    protected $table = 'order_sync_changes';

    protected $fillable = [
        'order_id',
        'sync_run_id',
        'field',
        'old_value',
        'new_value',
        'action',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(OrderSyncRun::class);
    }
}
