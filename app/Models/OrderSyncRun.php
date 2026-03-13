<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSyncRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'status',
        'requested_by',
        'stores',
        'from_date',
        'to_date',
        'total_orders',
        'synced_orders',
        'failed_stores',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'stores' => 'array',
            'failed_stores' => 'array',
            'from_date' => 'datetime',
            'to_date' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
