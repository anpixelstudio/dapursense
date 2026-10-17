<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Domain\Organization\TenantProvisioner;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private TenantProvisioner $provisioner) {}

    /**
     * Registration creates the user, a new tenant and the owner membership in one
     * transaction. When the guest came from an invitation link for the same email,
     * no tenant is created; the invitation is accepted on the page they return to.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $invitation = Invitation::pendingFromIntendedUrl(session('url.intended'));
        $joinsInvitation = $invitation !== null
            && strtolower(trim($input['email'] ?? '')) === $invitation->email;

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'organization_name' => [Rule::requiredIf(! $joinsInvitation), 'nullable', 'string', 'min:2', 'max:120'],
            'terms' => ['accepted'],
        ], [
            'terms.accepted' => 'You must accept the terms of use.',
        ], [
            'organization_name' => 'organization name',
        ])->validate();

        return DB::transaction(function () use ($input, $joinsInvitation) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            if ($joinsInvitation) {
                // The invitation token was delivered to this address, which proves ownership.
                $user->forceFill(['email_verified_at' => now()])->save();

                return $user;
            }

            $tenant = $this->provisioner->create($user, trim($input['organization_name']));

            Audit::log('tenant.registered', $tenant, ['owner_email' => $user->email], $tenant->id);

            return $user;
        });
    }
}
