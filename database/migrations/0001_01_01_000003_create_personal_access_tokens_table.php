<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum tokens for both audiences (User and AdminUser), with string morphs
 * for prefixed ULID keys and session metadata for GET /me/sessions, plus the
 * customer refresh tokens with rotation and family-based reuse detection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('tokenable_type', 60);
            $table->string('tokenable_id', 40);
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('platform', 10)->nullable();
            $table->string('location', 120)->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();
            $table->index(['tokenable_type', 'tokenable_id']);
        });

        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('user_id', 40);
            $table->string('token_hash', 64)->unique();
            $table->string('family_id', 40);
            $table->string('access_token_id', 40)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('replaced_by_id', 40)->nullable();
            // Session metadata: a session (GET /me/sessions) is a refresh-token family.
            $table->string('device_name', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('platform', 10)->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();
            $table->index('family_id');
            $table->index('access_token_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
        Schema::dropIfExists('personal_access_tokens');
    }
};
