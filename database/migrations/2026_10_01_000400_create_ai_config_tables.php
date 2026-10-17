<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scope', 16);
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->string('name');
            $table->string('version', 64);
            $table->string('artifact_key')->nullable();
            $table->string('sha256', 64);
            $table->string('runtime', 32);
            $table->jsonb('labels')->default('[]');
            $table->jsonb('capabilities')->default('{}');
            $table->string('license_ref')->nullable();
            $table->string('validation_status', 16)->default('DRAFT');
            $table->timestampsTz();
            $table->unique(['name', 'version']);
        });

        Schema::create('rule_sets', function (Blueprint $table) {
            $table->tenantRoot();
            $table->string('name');
            $table->uuid('active_revision_id')->nullable();
            $table->timestampsTz();
        });

        Schema::create('rule_revisions', function (Blueprint $table) {
            $table->tenantRoot();
            $table->tenantForeign('rule_set_id', 'rule_sets');
            $table->unsignedInteger('version');
            $table->unsignedInteger('schema_version')->default(1);
            $table->jsonb('config');
            $table->string('checksum', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['rule_set_id', 'version']);
        });

        Schema::table('rule_sets', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'active_revision_id'])
                ->references(['tenant_id', 'id'])
                ->on('rule_revisions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rule_sets', fn (Blueprint $table) => $table->dropForeign(['tenant_id', 'active_revision_id']));
        Schema::dropIfExists('rule_revisions');
        Schema::dropIfExists('rule_sets');
        Schema::dropIfExists('ai_models');
    }
};
