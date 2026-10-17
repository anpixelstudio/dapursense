<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Looked up by token hash across tenants, so it is intentionally not tenant-scoped.
 */
class Invitation extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at'];

    protected function casts(): array
    {
        return ['role' => Role::class, 'expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /**
     * Guests opening an invitation link are sent to login with the link stored as the
     * intended URL; this resolves it so registration can join instead of creating a tenant.
     */
    public static function pendingFromIntendedUrl(?string $url): ?self
    {
        if (! $url || ! preg_match('#/invitations/([A-Za-z0-9]+)/?$#', (string) parse_url($url, PHP_URL_PATH), $m)) {
            return null;
        }

        $invitation = static::query()->with('tenant')->where('token_hash', hash('sha256', $m[1]))->first();

        return $invitation?->isUsable() ? $invitation : null;
    }
}
