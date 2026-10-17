<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false);
        });

        Schema::create('system_installations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('target_version', 64);
            $table->string('operation', 16);
            $table->string('status', 16);
            $table->string('last_step', 64)->nullable();
            $table->string('report_key')->nullable();
            $table->jsonb('manifest')->default('{}');
            $table->string('error_code', 64)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('system_releases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('app_version', 64)->unique();
            $table->string('git_sha', 64)->nullable();
            $table->string('build_id', 64)->nullable();
            $table->string('schema_hash', 64);
            $table->jsonb('migration_manifest');
            $table->timestampTz('installed_at');
            $table->timestampsTz();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->jsonb('value');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('system_releases');
        Schema::dropIfExists('system_installations');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_platform_admin'));
    }
};
