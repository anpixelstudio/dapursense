<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\AnalysisRun;
use App\Models\Kitchen;
use App\Models\MediaSource;
use App\Models\VideoAsset;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SourceController extends Controller
{
    public function index(): Response
    {
        $this->authorizePermission(Permission::ViewData);

        return Inertia::render('sources/index', [
            'sources' => MediaSource::query()
                ->with(['kitchen:id,name,property_id', 'kitchen.property:id,name'])
                ->withCount(['videos', 'zones' => fn ($q) => $q->where('active', true)])
                ->orderBy('name')
                ->get(),
            'kitchens' => Kitchen::query()->with('property:id,name')->orderBy('name')->get(['id', 'name', 'property_id']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageSources);

        $validated = $request->validate([
            'kitchen_id' => ['required', 'uuid', Rule::exists('kitchens', 'id')->where('tenant_id', $this->tenantId())],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:upload'],
        ], ['type.in' => 'Sumber RTSP tersedia pada tahap P1; gunakan upload video.']);

        $source = MediaSource::query()->create($validated + ['status' => 'active']);
        Audit::log('source.created', $source);
        $this->toast('Sumber video ditambahkan.');

        return back();
    }

    public function update(Request $request, MediaSource $source): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageSources, $source);

        $source->update($request->validate([
            'name' => ['required', 'string', 'max:120'],
            'status' => ['required', 'in:active,inactive'],
        ]));
        Audit::log('source.updated', $source, $source->getChanges());
        $this->toast('Sumber video diperbarui.');

        return back();
    }

    public function destroy(MediaSource $source): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageSources, $source);

        $hasEvidence = VideoAsset::query()->where('source_id', $source->id)->exists()
            || AnalysisRun::query()->where('source_id', $source->id)->exists();

        if ($hasEvidence) {
            return back()->withErrors(['source' => 'Sumber memiliki video atau hasil analisis; nonaktifkan saja agar bukti tetap tersimpan.']);
        }

        $source->zones()->delete();
        $source->delete();
        Audit::log('source.deleted', $source);
        $this->toast('Sumber video dihapus.');

        return back();
    }
}
