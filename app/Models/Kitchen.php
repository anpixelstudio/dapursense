<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Kitchen extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'property_id', 'name', 'description'];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(MediaSource::class);
    }
}
