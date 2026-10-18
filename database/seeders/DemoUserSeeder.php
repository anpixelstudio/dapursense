<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Public judge / demo account with full access (Owner on all tenants + platform admin).
 *
 * Email:    demo@vera.app
 * Password: VeraDemo2026!
 */
class DemoUserSeeder extends Seeder
{
    public const EMAIL = 'demo@vera.app';

    public const PASSWORD = 'VeraDemo2026!';

    public function run(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'VERA Demo',
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
                'is_platform_admin' => true,
            ],
        );

        // Ensure platform admin + verified even if row already existed with weak flags.
        $user->forceFill([
            'name' => 'VERA Demo',
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => $user->email_verified_at ?? now(),
            'is_platform_admin' => true,
        ])->save();

        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            TenantMembership::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => Role::Owner,
                    'status' => 'active',
                ],
            );
        }
    }
}
