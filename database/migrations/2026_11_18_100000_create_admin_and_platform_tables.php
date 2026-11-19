<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin identity and RBAC, audit and security logs, platform settings, CMS,
 * banners, support, uploads/media, idempotency keys, webhook events,
 * integration logs, system alerts and export jobs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email');
            $table->string('password')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->unsignedBigInteger('two_factor_last_used_step')->nullable();
            $table->string('avatar_url', 1024)->nullable();
            $table->string('status', 20)->default('invited');
            $table->string('invite_token_hash', 64)->nullable();
            $table->timestampTz('invite_expires_at')->nullable();
            $table->string('invited_by_admin_id', 40)->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX admin_users_email_unique ON admin_users (lower(email))');

        Schema::create('admin_roles', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('description', 500)->default('');
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();
        });

        Schema::create('admin_role_permissions', function (Blueprint $table) {
            $table->string('role_id', 40);
            $table->string('permission_key', 60);
            $table->primary(['role_id', 'permission_key']);
            $table->foreign('role_id')->references('id')->on('admin_roles')->cascadeOnDelete();
        });

        Schema::create('admin_user_roles', function (Blueprint $table) {
            $table->string('admin_user_id', 40);
            $table->string('role_id', 40);
            $table->primary(['admin_user_id', 'role_id']);
            $table->foreign('admin_user_id')->references('id')->on('admin_users')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('admin_roles')->cascadeOnDelete();
        });

        // Append-only. Application code never updates or deletes rows.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('admin_user_id', 40)->nullable();
            $table->string('action', 100);
            $table->string('module', 60);
            $table->string('target_type', 60)->nullable();
            $table->string('target_id', 40)->nullable();
            $table->string('target_label', 255)->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['module', 'created_at']);
            $table->index(['target_type', 'target_id']);
            $table->index(['admin_user_id', 'created_at']);
        });

        Schema::create('security_logs', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('admin_user_id', 40)->nullable();
            $table->string('email_attempted')->nullable();
            $table->string('event', 40);
            $table->string('ip', 45)->nullable();
            $table->string('location', 120)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->jsonb('context')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['event', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->jsonb('value');
            $table->string('updated_by_admin_id', 40)->nullable();
            $table->timestampsTz();
        });

        Schema::create('banners', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('title', 200);
            $table->string('subtitle', 500)->nullable();
            $table->string('image_url', 1024)->nullable();
            $table->string('cta_label', 60)->nullable();
            $table->string('cta_url', 1024)->nullable();
            $table->string('placement', 30)->default('home_hero');
            $table->string('audience', 20)->default('all');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestampsTz();
            $table->index(['placement', 'is_active']);
        });

        Schema::create('cms_pages', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('slug', 30)->unique();
            $table->string('title', 200);
            $table->text('content');
            $table->string('updated_by_admin_id', 40)->nullable();
            $table->timestampsTz();
        });

        Schema::create('cms_page_versions', function (Blueprint $table) {
            $table->id();
            $table->string('cms_page_id', 40);
            $table->string('title', 200);
            $table->text('content');
            $table->string('updated_by_admin_id', 40)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('cms_page_id')->references('id')->on('cms_pages')->cascadeOnDelete();
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('reference', 20)->unique();
            $table->string('user_id', 40)->nullable();
            $table->string('subject', 200);
            $table->string('category', 20);
            $table->string('status', 20)->default('open');
            $table->string('priority', 20)->default('medium');
            $table->string('assigned_admin_id', 40)->nullable();
            $table->string('source', 10)->default('web');
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
            $table->index('user_id');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_admin_id')->references('id')->on('admin_users')->nullOnDelete();
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('ticket_id', 40);
            $table->string('author_user_id', 40)->nullable();
            $table->string('author_admin_id', 40)->nullable();
            $table->text('body');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['ticket_id', 'created_at']);
            $table->foreign('ticket_id')->references('id')->on('support_tickets')->cascadeOnDelete();
        });

        Schema::create('uploads', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('owner_user_id', 40)->nullable();
            $table->string('owner_admin_id', 40)->nullable();
            $table->string('purpose', 30);
            $table->string('provider', 20);
            $table->string('provider_public_id', 512)->nullable();
            $table->string('resource_type', 20)->nullable();
            $table->string('disk_path', 1024)->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('thumbnail_url', 2048)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestampTz('attached_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->index(['attached_at', 'expires_at']);
            $table->index('owner_user_id');
        });

        // Media shared by works, statuses and message attachments.
        Schema::create('media', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('owner_type', 60);
            $table->string('owner_id', 40);
            $table->string('upload_id', 40)->nullable();
            $table->string('type', 20);
            $table->string('url', 2048);
            $table->string('thumbnail_url', 2048)->nullable();
            $table->string('name', 255)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->jsonb('waveform')->nullable();
            $table->string('provider', 20)->nullable();
            $table->string('provider_public_id', 512)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 191);
            $table->string('user_id', 40);
            $table->string('endpoint', 191);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'endpoint', 'key']);
            $table->index('created_at');
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('provider', 30);
            $table->string('provider_event_id', 191);
            $table->string('type', 100)->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->jsonb('payload');
            $table->string('status', 20)->default('received');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();
            $table->unique(['provider', 'provider_event_id']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('operation', 60);
            $table->string('method', 10)->nullable();
            $table->string('endpoint', 512)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->boolean('success')->default(false);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->jsonb('request')->nullable();
            $table->jsonb('response')->nullable();
            $table->text('error')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['provider', 'created_at']);
        });

        Schema::create('system_alerts', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('severity', 20);
            $table->string('title', 200);
            $table->string('description', 1000);
            $table->string('link', 512)->nullable();
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->index(['resolved_at', 'created_at']);
        });

        Schema::create('export_jobs', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('admin_user_id', 40);
            $table->string('report_key', 60);
            $table->jsonb('query');
            $table->string('format', 10);
            $table->string('status', 20)->default('queued');
            $table->string('file_path', 1024)->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->foreign('admin_user_id')->references('id')->on('admin_users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['export_jobs', 'system_alerts', 'integration_logs', 'webhook_events', 'idempotency_keys', 'media', 'uploads',
            'support_messages', 'support_tickets', 'cms_page_versions', 'cms_pages', 'banners', 'platform_settings',
            'security_logs', 'audit_logs', 'admin_user_roles', 'admin_role_permissions', 'admin_roles', 'admin_users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
