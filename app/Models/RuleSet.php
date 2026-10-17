<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RuleSet extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'name', 'active_revision_id'];

    public function revisions(): HasMany
    {
        return $this->hasMany(RuleRevision::class)->orderByDesc('version');
    }

    public function activeRevision(): BelongsTo
    {
        return $this->belongsTo(RuleRevision::class, 'active_revision_id');
    }
}
