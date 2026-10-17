<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'slug', 'timezone', 'settings', 'status'];

    protected $attributes = ['settings' => '{}', 'status' => 'active', 'timezone' => 'Asia/Jakarta'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }
}
