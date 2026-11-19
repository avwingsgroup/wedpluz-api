<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Event managers, vendor assignments, tasks, activity log and the
 * "earn with Wedpluz" promotion programme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_managers', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('event_id', 40);
            $table->string('user_id', 40);
            $table->string('invited_by_user_id', 40)->nullable();
            $table->string('access', 10)->default('partial');
            $table->jsonb('permissions');
            $table->string('status', 20)->default('pending');
            $table->timestampTz('invited_at');
            $table->timestampTz('responded_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'status']);
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        DB::statement("CREATE UNIQUE INDEX event_managers_active_unique ON event_managers (event_id, user_id) WHERE status NOT IN ('declined','removed')");

        Schema::create('event_vendor_assignments', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('event_id', 40);
            $table->string('vendor_profile_id', 40);
            $table->string('invited_by_user_id', 40)->nullable();
            $table->string('role', 120)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestampTz('invited_at');
            $table->timestampTz('responded_at')->nullable();
            $table->timestampsTz();
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('vendor_profile_id')->references('id')->on('vendor_profiles')->cascadeOnDelete();
        });
        DB::statement("CREATE UNIQUE INDEX event_vendor_active_unique ON event_vendor_assignments (event_id, vendor_profile_id) WHERE status NOT IN ('declined','removed')");

        Schema::create('event_tasks', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('event_id', 40);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('todo');
            $table->timestampTz('due_at')->nullable();
            $table->string('created_by_user_id', 40);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['event_id', 'created_at']);
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });

        Schema::create('event_task_assignees', function (Blueprint $table) {
            $table->string('task_id', 40);
            $table->string('user_id', 40);
            $table->primary(['task_id', 'user_id']);
            $table->index('user_id');
            $table->foreign('task_id')->references('id')->on('event_tasks')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('event_task_attachments', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('task_id', 40);
            $table->string('upload_id', 40)->nullable();
            $table->string('name', 255);
            $table->string('url', 2048);
            $table->timestampsTz();
            $table->foreign('task_id')->references('id')->on('event_tasks')->cascadeOnDelete();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('event_id', 40);
            $table->string('actor_user_id', 40)->nullable();
            $table->string('actor_role', 20);
            $table->string('action', 60);
            $table->string('description', 500);
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['event_id', 'created_at']);
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });

        Schema::create('event_promotions', function (Blueprint $table) {
            $table->string('event_id', 40)->primary();
            $table->boolean('enabled')->default(false);
            $table->decimal('commission_percent', 5, 2)->default(10);
            $table->unsignedInteger('target_signups')->nullable();
            $table->unsignedInteger('promoter_limit')->nullable();
            $table->timestampsTz();
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE event_promotions ADD CONSTRAINT event_promotions_percent_check CHECK (commission_percent BETWEEN 1 AND 50)');

        Schema::create('promoter_enrollments', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('event_id', 40);
            $table->string('user_id', 40);
            $table->string('share_code', 20)->unique();
            $table->string('status', 20)->default('active');
            $table->timestampTz('joined_at');
            $table->timestampsTz();
            $table->unique(['event_id', 'user_id']);
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('promoter_commissions', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('enrollment_id', 40);
            $table->string('order_id', 40)->unique();
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 20)->default('pending');
            $table->timestampTz('credited_at')->nullable();
            $table->timestampsTz();
            $table->index(['enrollment_id', 'status']);
            $table->foreign('enrollment_id')->references('id')->on('promoter_enrollments')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['promoter_commissions', 'promoter_enrollments', 'event_promotions', 'activity_logs', 'event_task_attachments',
            'event_task_assignees', 'event_tasks', 'event_vendor_assignments', 'event_managers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
