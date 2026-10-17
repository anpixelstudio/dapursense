<?php

namespace App\Domain\Organization;

use App\Models\RuleRevision;
use App\Models\RuleSet;
use App\Models\User;
use App\Support\Canonical;
use Illuminate\Support\Facades\DB;

class RulePublisher
{
    /** @param array<string, mixed> $config */
    public function publish(RuleSet $ruleSet, array $config, ?User $actor = null): RuleRevision
    {
        return DB::transaction(function () use ($ruleSet, $config, $actor) {
            RuleSet::query()->whereKey($ruleSet->id)->lockForUpdate()->first();

            $version = (int) RuleRevision::query()
                ->where('tenant_id', $ruleSet->tenant_id)
                ->where('rule_set_id', $ruleSet->id)
                ->max('version') + 1;

            $revision = RuleRevision::query()->create([
                'tenant_id' => $ruleSet->tenant_id,
                'rule_set_id' => $ruleSet->id,
                'version' => $version,
                'schema_version' => SopTemplate::SCHEMA_VERSION,
                'config' => $config,
                'checksum' => Canonical::hash($config),
                'created_by' => $actor?->id,
            ]);

            $ruleSet->forceFill(['active_revision_id' => $revision->id])->save();

            return $revision;
        });
    }
}
