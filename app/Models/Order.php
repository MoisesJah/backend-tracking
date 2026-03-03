<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'external_id',
        'status',
        'total',
        'currency',
        'customer_name',
        'meta',
        'synced_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function timelines(): HasMany
    {
        return $this->hasMany(OrderTimeline::class);
    }
}
