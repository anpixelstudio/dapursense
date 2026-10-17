<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\ZoneType;
use App\Models\MediaSource;
use App\Models\Zone;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ZoneController extends Controller
{
    public function store(Request $request, MediaSource $source): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageSources, $source);

        $zone = Zone::query()->create($this->validated($request) + ['source_id' => $source->id, 'revision' => 1]);
        Audit::log('zone.created', $zone);
        $this->toast('Zona disimpan.');

        return back();
    }

    public function update(Request $request, Zone $zone): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageSources, $zone);
        $validated = $this->validated($request);

        if ($validated['points'] !== $zone->points || $validated['type'] !== $zone->type->value) {
            $validated['revision'] = $zone->revision + 1;
        }

        $zone->update($validated);
        Audit::log('zone.updated', $zone, ['revision' => $zone->revision]);
        $this->toast('Zona diperbarui; analisis berikutnya memakai revisi baru.');

        return back();
    }

    public function destroy(Zone $zone): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageSources, $zone);

        $zone->update(['active' => false]);
        Audit::log('zone.deactivated', $zone);
        $this->toast('Zona dinonaktifkan. Hasil analisis lama tetap memakai snapshot zona.');

        return back();
    }

    public function duplicate(Request $request, Zone $zone): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageSources, $zone);

        $validated = $request->validate([
            'target_source_id' => ['required', 'uuid', Rule::notIn([$zone->source_id]),
                Rule::exists('media_sources', 'id')->where('tenant_id', $this->tenantId())],
        ], ['target_source_id.not_in' => 'Pilih sumber dengan sudut kamera lain.']);

        $copy = Zone::query()->create([
            'source_id' => $validated['target_source_id'],
            'name' => $zone->name.' (salinan)',
            'type' => $zone->type,
            'points' => $zone->points,
            'revision' => 1,
        ]);
        Audit::log('zone.duplicated', $copy, ['from' => $zone->id]);
        $this->toast('Zona disalin. Sesuaikan polygon dengan sudut kamera sumber tujuan.');

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::enum(ZoneType::class)],
            'points' => ['required', 'array', 'min:3', 'max:50'],
            'points.*' => ['required', 'array', 'size:2'],
            'points.*.*' => ['required', 'numeric', 'between:0,1'],
        ], ['points.min' => 'Polygon membutuhkan minimal 3 titik.']);

        $validated['points'] = array_map(
            fn ($p) => [round((float) $p[0], 5), round((float) $p[1], 5)],
            $validated['points'],
        );

        return $validated;
    }
}
