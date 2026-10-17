<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Kitchen;
use App\Models\MediaSource;
use App\Models\Property;
use App\Support\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LocationController extends Controller
{
    public function index(): Response
    {
        $this->authorizePermission(Permission::ViewData);

        return Inertia::render('locations/index', [
            'properties' => Property::query()
                ->with(['kitchens' => fn ($q) => $q->withCount('sources')->orderBy('name')])
                ->orderBy('name')
                ->get(),
            'timezones' => ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura', 'Asia/Singapore', 'UTC'],
        ]);
    }

    public function storeProperty(Request $request): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageLocations);
        $property = Property::query()->create($this->propertyRules($request));
        Audit::log('property.created', $property);
        $this->toast('Properti ditambahkan.');

        return back();
    }

    public function updateProperty(Request $request, Property $property): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageLocations, $property);
        $property->update($this->propertyRules($request));
        Audit::log('property.updated', $property, $property->getChanges());
        $this->toast('Properti diperbarui.');

        return back();
    }

    public function destroyProperty(Property $property): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageLocations, $property);

        if ($property->kitchens()->exists()) {
            return back()->withErrors(['property' => 'Hapus dapur di properti ini terlebih dahulu.']);
        }

        $property->delete();
        Audit::log('property.deleted', $property);
        $this->toast('Properti dihapus.');

        return back();
    }

    public function storeKitchen(Request $request): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageLocations);
        $kitchen = Kitchen::query()->create($this->kitchenRules($request));
        Audit::log('kitchen.created', $kitchen);
        $this->toast('Dapur ditambahkan.');

        return back();
    }

    public function updateKitchen(Request $request, Kitchen $kitchen): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageLocations, $kitchen);
        $kitchen->update($this->kitchenRules($request));
        Audit::log('kitchen.updated', $kitchen, $kitchen->getChanges());
        $this->toast('Dapur diperbarui.');

        return back();
    }

    public function destroyKitchen(Kitchen $kitchen): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageLocations, $kitchen);

        if (MediaSource::query()->where('kitchen_id', $kitchen->id)->exists()) {
            return back()->withErrors(['kitchen' => 'Dapur masih memiliki sumber video.']);
        }

        try {
            $kitchen->delete();
        } catch (QueryException) {
            return back()->withErrors(['kitchen' => 'Dapur masih direferensikan data lain.']);
        }

        Audit::log('kitchen.deleted', $kitchen);
        $this->toast('Dapur dihapus.');

        return back();
    }

    /** @return array<string, mixed> */
    private function propertyRules(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'timezone' => ['required', 'timezone:all'],
        ]);
    }

    /** @return array<string, mixed> */
    private function kitchenRules(Request $request): array
    {
        return $request->validate([
            'property_id' => ['required', 'uuid', Rule::exists('properties', 'id')->where('tenant_id', $this->tenantId())],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
