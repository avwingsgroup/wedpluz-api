<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communities', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('event_id', 40)->nullable();
            $table->string('owner_user_id', 40);
            $table->string('name', 200);
            $table->string('description', 2000)->nullable();
            $table->string('avatar_url', 1024)->nullable();
            $table->jsonb('permissions');
            $table->string('invite_code', 20)->unique();
            $table->string('status', 20)->default('active');
            $table->timestampTz('archive_at')->nullable();
            $table->timestampsTz();
            $table->index('status');
            $table->foreign('event_id')->references('id')->on('events')->nullOnDelete();
            $table->foreign('owner_user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX communities_event_unique ON communities (event_id) WHERE event_id IS NOT NULL');

        Schema::create('community_members', function (Blueprint $table) {
            $table->string('community_id', 40);
            $table->string('user_id', 40);
            $table->string('role', 20)->default('member');
            $table->string('status', 20)->default('active');
            $table->boolean('is_muted')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->timestampTz('joined_at')->useCurrent();
            $table->primary(['community_id', 'user_id']);
            $table->index('user_id');
            $table->foreign('community_id')->references('id')->on('communities')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('community_invites', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('community_id', 40);
            $table->string('invited_by_user_id', 40);
            $table->string('user_id', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestampsTz();
            $table->foreign('community_id')->references('id')->on('communities')->cascadeOnDelete();
        });

        Schema::create('status_posts', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('community_id', 40);
            $table->string('author_user_id', 40);
            $table->string('media_url', 2048);
            $table->string('media_type', 10);
            $table->string('upload_id', 40)->nullable();
            $table->string('caption', 500)->nullable();
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('views_count')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('media_purged_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['community_id', 'expires_at']);
            $table->foreign('community_id')->references('id')->on('communities')->cascadeOnDelete();
            $table->foreign('author_user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        foreach (['status_views', 'status_likes'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->string('status_id', 40);
                $table->string('user_id', 40);
                $table->timestampTz('created_at')->useCurrent();
                $table->primary(['status_id', 'user_id']);
                $table->foreign('status_id')->references('id')->on('status_posts')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        Schema::create('live_sessions', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('community_id', 40);
            $table->string('host_user_id', 40);
            $table->string('title', 200)->nullable();
            $table->string('status', 20)->default('live');
            $table->string('provider', 20)->nullable();
            $table->string('provider_room_id', 191)->nullable();
            $table->boolean('audio_enabled')->default(true);
            $table->boolean('video_enabled')->default(true);
            $table->unsignedInteger('viewers_count')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampsTz();
            $table->foreign('community_id')->references('id')->on('communities')->cascadeOnDelete();
        });
        DB::statement("CREATE UNIQUE INDEX live_sessions_one_live ON live_sessions (community_id) WHERE status = 'live'");

        Schema::create('live_requests', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('live_session_id', 40);
            $table->string('user_id', 40);
            $table->string('status', 20)->default('pending');
            $table->timestampsTz();
            $table->unique(['live_session_id', 'user_id']);
            $table->foreign('live_session_id')->references('id')->on('live_sessions')->cascadeOnDelete();
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('type', 20);
            $table->string('community_id', 40)->nullable()->unique();
            $table->string('direct_key', 100)->nullable()->unique();
            $table->string('last_message_id', 40)->nullable();
            $table->timestampTz('last_message_at')->nullable();
            $table->timestampsTz();
            $table->foreign('community_id')->references('id')->on('communities')->cascadeOnDelete();
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->string('conversation_id', 40);
            $table->string('user_id', 40);
            $table->string('last_read_message_id', 40)->nullable();
            $table->timestampTz('last_read_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_muted')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->boolean('is_request')->default(false);
            $table->timestampTz('request_declined_at')->nullable();
            $table->timestampTz('cleared_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();
            $table->primary(['conversation_id', 'user_id']);
            $table->index(['user_id', 'deleted_at']);
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('conversation_id', 40);
            $table->string('sender_user_id', 40);
            $table->string('client_id', 64);
            $table->string('type', 20)->default('text');
            $table->text('body')->nullable();
            $table->string('reply_to_id', 40)->nullable();
            $table->string('event_id', 40)->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->string('deleted_by', 10)->default('none');
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['sender_user_id', 'client_id']);
            $table->index(['conversation_id', 'created_at']);
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('sender_user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('message_reactions', function (Blueprint $table) {
            $table->string('message_id', 40);
            $table->string('user_id', 40);
            $table->string('emoji', 32);
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['message_id', 'user_id', 'emoji']);
            $table->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('message_stars', function (Blueprint $table) {
            $table->string('message_id', 40);
            $table->string('user_id', 40);
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['message_id', 'user_id']);
            $table->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('content_reports', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('target_type', 20);
            $table->string('target_id', 40);
            $table->string('reporter_user_id', 40);
            $table->string('reported_user_id', 40)->nullable();
            $table->string('reason', 40);
            $table->string('details', 2000)->nullable();
            $table->string('status', 20)->default('open');
            $table->string('priority', 10)->default('low');
            $table->string('assigned_admin_id', 40)->nullable();
            $table->jsonb('target_preview')->nullable();
            $table->jsonb('resolution')->nullable();
            $table->timestampsTz();
            $table->unique(['reporter_user_id', 'target_type', 'target_id']);
            $table->index(['status', 'priority', 'created_at']);
            $table->index(['target_type', 'target_id']);
            $table->index('reported_user_id');
            $table->foreign('reporter_user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Soft-removed content kept 90 days for appeals.
        Schema::create('moderation_removals', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('target_type', 20);
            $table->string('target_id', 40);
            $table->string('report_id', 40)->nullable();
            $table->string('admin_user_id', 40);
            $table->string('note', 2000);
            $table->timestampTz('restored_at')->nullable();
            $table->timestampsTz();
            $table->index(['target_type', 'target_id']);
        });

        // In-app notifications (database channel with the contract's extra columns).
        Schema::create('notifications', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('user_id', 40);
            $table->string('type', 40);
            $table->string('actor_user_id', 40)->nullable();
            $table->string('title', 200);
            $table->string('body', 1000);
            $table->jsonb('segments');
            $table->string('action', 20)->default('none');
            $table->string('action_state', 20)->nullable();
            $table->jsonb('data');
            $table->string('link', 1024)->nullable();
            $table->boolean('is_request')->default(false);
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 40)->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'read_at', 'created_at']);
            $table->index(['user_id', 'is_request', 'created_at']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('key', 60);
            $table->string('name', 200);
            $table->string('channel', 20);
            $table->string('subject', 255)->nullable();
            $table->text('body');
            $table->jsonb('variables');
            $table->boolean('is_active')->default(true);
            $table->string('whatsapp_template_name', 120)->nullable();
            $table->timestampsTz();
            $table->unique(['key', 'channel']);
        });

        Schema::create('notification_campaigns', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('name', 200);
            $table->jsonb('channels');
            $table->jsonb('audience');
            $table->string('template_id', 40)->nullable();
            $table->string('subject', 255)->nullable();
            $table->text('body');
            $table->string('status', 20)->default('draft');
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->jsonb('stats');
            $table->string('created_by_admin_id', 40);
            $table->timestampsTz();
            $table->index(['status', 'scheduled_at']);
        });

        // Every outbound email/SMS/WhatsApp/push message, for delivery tracking and dedupe.
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('campaign_id', 40)->nullable();
            $table->string('notification_id', 40)->nullable();
            $table->string('user_id', 40)->nullable();
            $table->string('channel', 20);
            $table->string('recipient', 255)->nullable();
            $table->string('template_key', 60)->nullable();
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->string('provider', 30)->nullable();
            $table->string('provider_message_id', 191)->nullable();
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('clicked_at')->nullable();
            $table->timestampsTz();
            $table->index(['campaign_id', 'channel', 'status']);
            $table->index(['provider', 'provider_message_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('reason', 30);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        foreach (['email_suppressions', 'notification_deliveries', 'notification_campaigns', 'notification_templates', 'notifications',
            'moderation_removals', 'content_reports', 'message_stars', 'message_reactions', 'messages', 'conversation_participants',
            'conversations', 'live_requests', 'live_sessions', 'status_likes', 'status_views', 'status_posts', 'community_invites',
            'community_members', 'communities'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
