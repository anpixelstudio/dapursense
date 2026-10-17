<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IngestionBatch extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'run_id', 'attempt', 'batch_sequence', 'payload_sha256', 'item_count',
        'status', 'error_code', 'received_at',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
