<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_admin',
        'last_seen_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'role' => UserRole::class,
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Get all orders associated with this user.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get all audit logs created by this user.
     */
    public function orderLogs(): HasMany
    {
        return $this->hasMany(OrderLog::class);
    }

    /**
     * Check if user is admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN || (bool) ($this->is_admin ?? false);
    }

    /**
     * Check if user has a specific role.
     */
    public function hasRole(UserRole $role): bool
    {
        if ($role === UserRole::ADMIN) {
            return $this->isAdmin();
        }

        return $this->role === $role;
    }

    /**
     * Determine if the user can perform an action on the given order status.
     */
    public function canUpdateOrderStatus(\App\Enums\OrderStatus $newStatus): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $allowedStatuses = UserRole::allowedStatesForRole($this->role);
        return in_array($newStatus, $allowedStatuses);
    }
}
