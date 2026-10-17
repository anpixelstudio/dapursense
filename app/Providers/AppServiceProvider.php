<?php

namespace App\Providers;

use App\Domain\Ai\AiAssistant;
use App\Domain\Ai\GeminiAiAssistant;
use App\Domain\Ai\StubAiAssistant;
use App\Domain\System\ReleaseManifest;
use App\Enums\Permission;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(ReleaseManifest::class);

        $this->app->singleton(AiAssistant::class, function () {
            $config = config('kitchen.genai');

            if (($config['provider'] ?? 'stub') === 'gemini') {
                return new GeminiAiAssistant(
                    (string) ($config['api_key'] ?? ''),
                    (string) ($config['model'] ?? 'gemini-2.5-flash'),
                    (string) ($config['endpoint'] ?? 'https://generativelanguage.googleapis.com/v1beta'),
                );
            }

            return new StubAiAssistant;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerSchemaMacros();
        $this->registerGates();
    }

    /**
     * Every tenant-owned object is checked against the active membership:
     * same tenant and a role that grants the permission.
     */
    protected function registerGates(): void
    {
        Gate::define('permission', function (User $user, Permission $permission, ?Model $subject = null): Response {
            $context = app(TenantContext::class);

            if ($context->membership()?->user_id !== $user->id) {
                return Response::denyAsNotFound();
            }

            if ($subject !== null && $subject->getAttribute('tenant_id') !== $context->id()) {
                return Response::denyAsNotFound();
            }

            return $context->role()->can($permission)
                ? Response::allow()
                : Response::deny('Your role does not have permission for this action.');
        });

        Gate::define('platform-admin', fn (User $user) => $user->is_platform_admin);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Tenant-owned children reference (tenant_id, parent_id) so a row can never
     * point at a parent that belongs to another tenant.
     */
    protected function registerSchemaMacros(): void
    {
        Blueprint::macro('tenantRoot', function () {
            /** @var Blueprint $this */
            $this->uuid('id')->primary();
            $this->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $this->unique(['tenant_id', 'id']);
        });

        Blueprint::macro('tenantForeign', function (string $column, string $table, bool $nullable = false) {
            /** @var Blueprint $this */
            $definition = $this->uuid($column);

            if ($nullable) {
                $definition->nullable();
            }

            $this->foreign(['tenant_id', $column])
                ->references(['tenant_id', 'id'])
                ->on($table)
                ->restrictOnDelete();

            return $definition;
        });
    }
}
