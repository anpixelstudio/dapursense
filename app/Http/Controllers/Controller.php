<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

abstract class Controller
{
    protected function authorizePermission(Permission $permission, ?Model $subject = null): void
    {
        Gate::authorize('permission', [$permission, $subject]);
    }

    protected function tenantId(): string
    {
        return app(TenantContext::class)->requireId();
    }

    protected function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }
}
