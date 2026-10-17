<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaSource extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'kitchen_id', 'name', 'type', 'encrypted_connection', 'status'];

    protected $hidden = ['encrypted_connection'];

    protected function casts(): array
    {
        return ['encrypted_connection' => 'encrypted'];
    }

    public function kitchen(): BelongsTo
    {
        return $this->belongsTo(Kitchen::class);
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class, 'source_id');
    }

    public function videos(): HasMany
    {
        return $this->hasMany(VideoAsset::class, 'source_id');
    }
}
