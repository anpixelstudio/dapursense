<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Not tenant-scoped: memberships are how the tenant context is resolved.
 */
class TenantMembership extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'user_id', 'role', 'status'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['role' => Role::class];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->tenant?->status === 'active';
    }
}
