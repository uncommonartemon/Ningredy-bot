<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Customers who see prices of their own (operator, 2026-11-11). Its invitation link is the
 * only way to sign up: whoever opens it becomes a customer of this group.
 */
class CustomerGroup extends Model
{
    protected $fillable = ['name', 'invite_code', 'is_active', 'registration_open', 'note', 'sort_order'];

    protected $attributes = ['is_active' => true, 'registration_open' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'registration_open' => 'boolean', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (CustomerGroup $group): void {
            $group->name = trim((string) $group->name);
            if (blank($group->invite_code)) {
                $group->invite_code = self::newInviteCode();
            }
        });
    }

    public static function newInviteCode(): string
    {
        return Str::lower(Str::random(24));
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(CustomerGroupPrice::class);
    }

    /** The link a new customer of this group signs up through. */
    public function inviteUrl(): string
    {
        return url('/account/join/'.$this->invite_code);
    }

    /** Whether the link still lets someone in. */
    public function acceptsRegistration(): bool
    {
        return $this->is_active && $this->registration_open;
    }
}
