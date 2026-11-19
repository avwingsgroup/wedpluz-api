<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer identity: users, platform roles, social accounts, OTPs, privacy and
 * notification preferences, device tokens and interests.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('users', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('display_name', 200);
            $table->string('username', 30)->nullable();
            $table->string('email', 255);
            $table->string('phone', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('password')->nullable();
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('phone_verified_at')->nullable();
            $table->string('identity_status', 20)->default('unverified');
            $table->string('status', 20)->default('active');
            $table->string('plan', 20)->default('free');
            $table->string('locale', 10)->default('en');
            $table->string('city', 120)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('avatar_url', 1024)->nullable();
            $table->string('bio', 500)->nullable();
            $table->string('suspended_reason', 1000)->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->string('signup_source', 10)->default('web');
            $table->boolean('is_vendor')->default(false);
            $table->boolean('profile_completed')->default(false);
            $table->boolean('interests_completed')->default(false);
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('anonymized_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (lower(email)) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX users_username_unique ON users (lower(username)) WHERE deleted_at IS NULL AND username IS NOT NULL');
        DB::statement('CREATE INDEX users_name_trgm ON users USING gin ((first_name || \' \' || last_name || \' \' || coalesce(username, \'\')) gin_trgm_ops)');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active','suspended','deactivated','pending_deletion'))");

        Schema::create('user_roles', function (Blueprint $table) {
            $table->string('user_id', 40);
            $table->string('role', 20);
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['user_id', 'role']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 40);
            $table->string('provider', 20);
            $table->string('provider_user_id', 191);
            $table->string('email')->nullable();
            $table->timestampsTz();
            $table->unique(['provider', 'provider_user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('user_id', 40)->nullable();
            $table->string('purpose', 30);
            $table->string('code_hash');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
            $table->index(['email', 'purpose']);
        });

        Schema::create('user_privacy_settings', function (Blueprint $table) {
            $table->string('user_id', 40)->primary();
            $table->string('profile_visibility', 20)->default('public');
            $table->boolean('show_email')->default(false);
            $table->boolean('show_phone')->default(false);
            $table->boolean('allow_message_requests')->default(true);
            $table->boolean('allow_tagging')->default(true);
            $table->boolean('discoverable_by_search')->default(true);
            $table->timestampsTz();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->string('user_id', 40)->primary();
            $table->jsonb('channels');
            $table->jsonb('topics');
            $table->timestampsTz();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 40);
            $table->string('platform', 10);
            $table->string('token', 512)->unique();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
            $table->index('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('interests', function (Blueprint $table) {
            $table->string('slug', 60)->primary();
            $table->string('label', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('user_interests', function (Blueprint $table) {
            $table->string('user_id', 40);
            $table->string('interest_slug', 60);
            $table->primary(['user_id', 'interest_slug']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('interest_slug')->references('slug')->on('interests')->cascadeOnDelete();
        });

        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('user_id', 40);
            $table->string('id_type', 30);
            $table->text('id_number_encrypted');
            $table->string('id_number_last4', 4)->nullable();
            $table->string('selfie_upload_id', 40)->nullable();
            $table->string('provider', 30);
            $table->string('provider_reference', 191)->nullable();
            $table->string('status', 20)->default('pending');
            $table->decimal('match_score', 5, 2)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestampTz('submitted_at');
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('media_purged_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'created_at']);
            $table->index('provider_reference');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('account_deletion_requests', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('user_id', 40);
            $table->string('reason', 1000);
            $table->string('status', 20)->default('pending');
            $table->timestampTz('requested_at');
            $table->timestampTz('scheduled_for');
            $table->string('decided_by_admin_id', 40)->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'scheduled_for']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        DB::statement("CREATE UNIQUE INDEX account_deletion_one_pending ON account_deletion_requests (user_id) WHERE status = 'pending'");

        Schema::create('blocks', function (Blueprint $table) {
            $table->string('blocker_user_id', 40);
            $table->string('blocked_user_id', 40);
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['blocker_user_id', 'blocked_user_id']);
            $table->index('blocked_user_id');
            $table->foreign('blocker_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('blocked_user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('user_activities', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('user_id', 40);
            $table->string('type', 60);
            $table->string('description', 500);
            $table->string('source', 10)->default('web');
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['user_activities', 'blocks', 'account_deletion_requests', 'identity_verifications', 'user_interests', 'interests',
            'device_tokens', 'notification_preferences', 'user_privacy_settings', 'otp_codes', 'social_accounts', 'user_roles', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
