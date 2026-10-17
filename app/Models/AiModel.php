<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Global models have no tenant, so access is checked through availableTo()
 * instead of a composite foreign key.
 */
class AiModel extends Model
{
    use HasUuids;

    protected $fillable = [
        'scope', 'tenant_id', 'name', 'version', 'artifact_key', 'sha256', 'runtime',
        'labels', 'capabilities', 'license_ref', 'validation_status',
    ];

    protected function casts(): array
    {
        return ['labels' => 'array', 'capabilities' => 'array'];
    }

    public function scopeAvailableTo(Builder $query, string $tenantId): void
    {
        $query->whereIn('validation_status', ['VALIDATED', 'ACTIVE'])
            ->where(fn (Builder $q) => $q->where('scope', 'global')->orWhere('tenant_id', $tenantId));
    }

    public function isSimulator(): bool
    {
        return $this->runtime === 'simulation';
    }

    public function supports(string $capability): bool
    {
        return (bool) data_get($this->capabilities, $capability, false);
    }
}
