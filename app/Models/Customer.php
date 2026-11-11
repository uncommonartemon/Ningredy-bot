<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Someone who signs in to the shop to see their group's prices. Kept apart from the
 * admin's users: a customer's session never reaches the admin panel.
 */
class Customer extends Authenticatable
{
    protected $fillable = ['customer_group_id', 'name', 'email', 'phone', 'company', 'password', 'is_active', 'note', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean', 'last_login_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            $customer->email = mb_strtolower(trim((string) $customer->email));
            $customer->name = trim((string) $customer->name);
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }
}
