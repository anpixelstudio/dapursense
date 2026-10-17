<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantContext
{
    private ?TenantMembership $membership = null;

    private ?string $tenantId = null;

    public function set(TenantMembership $membership): void
    {
        $this->membership = $membership;
        $this->tenantId = $membership->tenant_id;
    }

    /**
     * Used by worker callbacks and jobs: scopes queries to a tenant without a user role.
     */
    public function setTenant(string $tenantId): void
    {
        $this->membership = null;
        $this->tenantId = $tenantId;
    }

    public function clear(): void
    {
        $this->membership = null;
        $this->tenantId = null;
    }

    public function has(): bool
    {
        return $this->tenantId !== null;
    }

    public function id(): ?string
    {
        return $this->tenantId;
    }

    public function requireId(): string
    {
        return $this->tenantId ?? throw new HttpException(403, 'Organisasi aktif belum dipilih.');
    }

    public function tenant(): ?Tenant
    {
        return $this->membership?->tenant ?? ($this->tenantId ? Tenant::query()->find($this->tenantId) : null);
    }

    public function membership(): ?TenantMembership
    {
        return $this->membership;
    }

    public function role(): ?Role
    {
        return $this->membership?->role;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(string $tenantId, callable $callback): mixed
    {
        [$membership, $previous] = [$this->membership, $this->tenantId];
        $this->setTenant($tenantId);

        try {
            return $callback();
        } finally {
            [$this->membership, $this->tenantId] = [$membership, $previous];
        }
    }
}
