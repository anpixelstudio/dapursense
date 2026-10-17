<?php

namespace App\Domain\Organization;

use App\Enums\Role;
use App\Models\RuleSet;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantProvisioner
{
    public function __construct(private RulePublisher $rules) {}

    public function create(User $owner, string $name, string $timezone = 'Asia/Jakarta'): Tenant
    {
        return DB::transaction(function () use ($owner, $name, $timezone) {
            $tenant = Tenant::query()->create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'timezone' => $timezone,
            ]);

            TenantMembership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $owner->id,
                'role' => Role::Owner,
            ]);

            $ruleSet = RuleSet::query()->create(['tenant_id' => $tenant->id, 'name' => 'SOP Dasar Dapur']);
            $this->rules->publish($ruleSet, SopTemplate::defaultConfig(), $owner);

            return $tenant;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organisasi';
        $slug = $base;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
