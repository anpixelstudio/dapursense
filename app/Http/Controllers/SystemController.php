<?php

namespace App\Http\Controllers;

use App\Domain\System\ReleaseManifest;
use App\Domain\System\SystemStatus;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\SystemInstallation;
use App\Models\SystemRelease;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SystemController extends Controller
{
    public function __invoke(Request $request, SystemStatus $status, ReleaseManifest $manifest): Response
    {
        $isPlatformAdmin = Gate::allows('platform-admin');

        if (! $isPlatformAdmin) {
            $this->authorizePermission(Permission::ManageOrganization);
        }

        return Inertia::render('system/index', [
            'status' => $status->collect(),
            'releases' => SystemRelease::query()->latest('installed_at')->limit(10)->get(['app_version', 'git_sha', 'schema_hash', 'installed_at']),
            'installations' => $isPlatformAdmin
                ? SystemInstallation::query()->latest('started_at')->limit(20)->get(['id', 'operation', 'target_version', 'status', 'last_step', 'error_code', 'started_at', 'finished_at'])
                : [],
            'audit' => AuditLog::query()
                ->where('tenant_id', app(TenantContext::class)->id())
                ->with('actor:id,name')
                ->latest('created_at')
                ->limit(50)
                ->get(),
            'contractVersion' => config('kitchen.worker.contract_version'),
            'isPlatformAdmin' => $isPlatformAdmin,
        ]);
    }
}
