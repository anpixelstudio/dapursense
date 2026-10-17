<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_sources', function (Blueprint $table) {
            $table->tenantRoot();
            $table->tenantForeign('kitchen_id', 'kitchens');
            $table->string('name');
            $table->string('type', 16);
            $table->text('encrypted_connection')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
        });

        Schema::create('video_assets', function (Blueprint $table) {
            $table->tenantRoot();
            $table->tenantForeign('source_id', 'media_sources');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('original_disk', 32);
            $table->string('original_key')->nullable();
            $table->string('preview_key')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedBigInteger('bytes');
            $table->bigInteger('duration_ms')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->decimal('fps', 8, 3)->nullable();
            $table->string('codec', 32)->nullable();
            $table->integer('rotation')->default(0);
            $table->timestampTz('recorded_at')->nullable();
            $table->string('recorded_timezone', 64)->nullable();
            $table->string('status', 16);
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('last_run_finished_at')->nullable();
            $table->timestampTz('original_expires_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('zones', function (Blueprint $table) {
            $table->tenantRoot();
            $table->tenantForeign('source_id', 'media_sources');
            $table->string('name');
            $table->string('type', 16);
            $table->unsignedInteger('revision')->default(1);
            $table->jsonb('points');
            $table->jsonb('geometry_snapshot')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
        Schema::dropIfExists('video_assets');
        Schema::dropIfExists('media_sources');
    }
};
