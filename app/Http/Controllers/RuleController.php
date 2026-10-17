<?php

namespace App\Http\Controllers;

use App\Domain\Organization\RulePublisher;
use App\Domain\Organization\SopTemplate;
use App\Enums\Permission;
use App\Models\AiModel;
use App\Models\RuleSet;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RuleController extends Controller
{
    public function index(): Response
    {
        $this->authorizePermission(Permission::ViewData);

        return Inertia::render('rules/index', [
            'ruleSets' => RuleSet::query()
                ->with(['activeRevision', 'revisions' => fn ($q) => $q->with('ruleSet:id')->limit(20)])
                ->orderBy('name')
                ->get()
                ->map(fn (RuleSet $set) => [
                    'id' => $set->id,
                    'name' => $set->name,
                    'active_revision' => $set->activeRevision,
                    'revisions' => $set->revisions->map->only(['id', 'version', 'checksum', 'created_at']),
                ]),
            'models' => AiModel::query()
                ->where(fn ($q) => $q->where('scope', 'global')->orWhere('tenant_id', $this->tenantId()))
                ->orderBy('name')
                ->get(['id', 'scope', 'name', 'version', 'runtime', 'capabilities', 'labels', 'license_ref', 'validation_status', 'sha256']),
            'template' => SopTemplate::defaultConfig(),
            'ppeItems' => SopTemplate::PPE_ITEMS,
        ]);
    }

    public function store(Request $request, RulePublisher $publisher): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageRules);
        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $ruleSet = RuleSet::query()->create($validated);
        $publisher->publish($ruleSet, SopTemplate::defaultConfig(), $request->user());
        Audit::log('rules.created', $ruleSet);
        $this->toast('Rule set created from SOP template.');

        return back();
    }

    public function publish(Request $request, RuleSet $ruleSet, RulePublisher $publisher): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageRules, $ruleSet);
        $validated = $request->validate(SopTemplate::validationRules());

        $revision = $publisher->publish($ruleSet, $validated['config'], $request->user());
        Audit::log('rules.published', $ruleSet, ['version' => $revision->version, 'checksum' => $revision->checksum]);
        $this->toast("Rule revision v{$revision->version} published.");

        return back();
    }
}
