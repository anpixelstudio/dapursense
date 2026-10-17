<?php

namespace App\Http\Middleware;

use App\Domain\System\ReleaseManifest;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $context = app(TenantContext::class);
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'tenant' => fn () => $context->has() ? [
                'id' => $context->id(),
                'name' => $context->tenant()->name,
                'timezone' => $context->tenant()->timezone,
                'role' => $context->role()->value,
                'permissions' => array_map(fn ($p) => $p->value, $context->role()->permissions()),
            ] : null,
            'memberships' => fn () => $user
                ? $user->activeMemberships()->with('tenant:id,name')->get()
                    ->map(fn ($m) => ['tenant_id' => $m->tenant_id, 'name' => $m->tenant->name, 'role' => $m->role->value])
                : [],
            'unreadNotifications' => fn () => $user && $context->has()
                ? $user->unreadNotifications()->where('data->tenant_id', $context->id())->count()
                : 0,
            'app' => fn () => [
                'version' => app(ReleaseManifest::class)->version(),
                'isPlatformAdmin' => (bool) $user?->is_platform_admin,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
