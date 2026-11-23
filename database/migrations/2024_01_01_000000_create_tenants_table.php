<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenants table lives in the CENTRAL database and is never tenant-scoped.
 * Skipped when the table already exists (apps upgrading from an older copy).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenants')) {
            return;
        }

        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            // Identification. Either or both.
            $table->string('domain')->nullable()->unique();
            $table->string('subdomain')->nullable()->unique();

            // starter = shared resources. business / enterprise = dedicated.
            $table->string('tier', 32)->default('starter');

            // Null = use the shared resource. Filled = dedicated resource.
            $table->json('db_config')->nullable();
            $table->json('redis_config')->nullable();
            $table->json('storage_config')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
