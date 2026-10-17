<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SystemRelease extends Model
{
    use HasUuids;

    protected $fillable = ['app_version', 'git_sha', 'build_id', 'schema_hash', 'migration_manifest', 'installed_at'];

    protected function casts(): array
    {
        return ['migration_manifest' => 'array', 'installed_at' => 'datetime'];
    }
}
