<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RuleRevision extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'rule_set_id', 'version', 'schema_version', 'config', 'checksum', 'created_by'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Rule revision bersifat immutable.'));
    }

    protected function casts(): array
    {
        return ['config' => 'array'];
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class);
    }
}
