<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Events and their children, plus the planner assets they reference
 * (hall layouts for seating, planner menus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_categories', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->string('icon', 60)->default('calendar');
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_review')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        // Seat IDs inside `tables` must stay stable: seat reservations reference them.
        Schema::create('hall_layouts', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('user_id', 40);
            $table->string('name', 200);
            $table->string('thumbnail_url', 1024)->nullable();
            $table->unsignedInteger('width')->default(1000);
            $table->unsignedInteger('height')->default(800);
            $table->unsignedInteger('total_seats')->default(0);
            $table->unsignedInteger('reserved_seats')->default(0);
            $table->jsonb('tables');
            $table->jsonb('fixtures');
            $table->string('event_id', 40)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('planner_menus', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('user_id', 40);
            $table->string('name', 200);
            $table->jsonb('sections');
            $table->char('currency', 3)->default('NGN');
            $table->string('event_id', 40)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('organizer_user_id', 40);
            $table->string('category_id', 40)->nullable();
            $table->string('slug', 220)->unique();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('cover_url', 1024)->nullable();
            $table->jsonb('gallery')->nullable();
            $table->jsonb('tags')->nullable();
            $table->string('visibility', 10)->default('public');
            $table->string('status', 20)->default('draft');
            $table->boolean('approval_required')->default(false);
            $table->unsignedInteger('guest_limit')->nullable();
            $table->boolean('allow_guest_invites')->default(false);
            $table->unsignedSmallInteger('max_additional_guests')->default(0);
            $table->boolean('is_adult_only')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_free')->default(false);
            $table->boolean('always_refundable')->default(false);
            $table->boolean('allow_ticket_transfer')->default(true);
            $table->boolean('community_enabled')->default(true);
            $table->char('currency', 3)->default('NGN');
            $table->jsonb('organizer_info')->nullable();
            $table->string('hall_layout_id', 40)->nullable();
            $table->string('menu_id', 40)->nullable();
            // Denormalised for listing speed; maintained by EventService.
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('attendance_mode', 10)->default('physical');
            $table->bigInteger('min_price')->default(0);
            $table->unsignedInteger('tickets_sold')->default(0);
            $table->unsignedInteger('saves_count')->default(0);
            $table->string('rejection_reason', 1000)->nullable();
            $table->string('cancellation_reason', 1000)->nullable();
            $table->string('suspended_reason', 1000)->nullable();
            $table->string('status_before_suspension', 20)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->string('reviewed_by_admin_id', 40)->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('source', 10)->default('web');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['status', 'starts_at']);
            $table->index(['status', 'visibility', 'starts_at']);
            $table->index('organizer_user_id');
            $table->index('category_id');
            $table->foreign('organizer_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('event_categories')->nullOnDelete();
            $table->foreign('reviewed_by_admin_id')->references('id')->on('admin_users')->nullOnDelete();
        });
        DB::statement('CREATE INDEX events_title_trgm ON events USING gin (title gin_trgm_ops)');
        DB::statement("ALTER TABLE events ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(description, ''))) STORED");
        DB::statement('CREATE INDEX events_search_vector ON events USING gin (search_vector)');

        Schema::create('event_schedules', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('event_id', 40);
            $table->string('label', 120)->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->string('attendance_mode', 10)->default('physical');
            $table->jsonb('venue')->nullable();
            $table->jsonb('online')->nullable();
            $table->string('city', 120)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('hall_layout_id', 40)->nullable();
            $table->string('menu_id', 40)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->index(['event_id', 'starts_at']);
            $table->index('starts_at');
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('hall_layout_id')->references('id')->on('hall_layouts')->nullOnDelete();
            $table->foreign('menu_id')->references('id')->on('planner_menus')->nullOnDelete();
        });

        Schema::create('sub_events', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('event_id', 40);
            $table->string('schedule_id', 40)->nullable();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('cover_url', 1024)->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->boolean('is_included_in_bundle')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('schedule_id')->references('id')->on('event_schedules')->nullOnDelete();
        });

        Schema::create('ticket_types', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('event_id', 40);
            $table->string('schedule_id', 40)->nullable();
            $table->string('name', 120);
            $table->string('description', 1000)->nullable();
            $table->bigInteger('price')->default(0);
            $table->char('currency', 3)->default('NGN');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('sold')->default(0);
            $table->timestampTz('sales_start_at')->nullable();
            $table->timestampTz('sales_end_at')->nullable();
            $table->unsignedSmallInteger('per_order_limit')->default(10);
            $table->boolean('seat_selection')->default(false);
            $table->boolean('menu_selection')->default(false);
            $table->boolean('is_early_bird')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('event_id');
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('schedule_id')->references('id')->on('event_schedules')->nullOnDelete();
        });
        DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT ticket_types_sold_check CHECK (sold <= quantity)');
        DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT ticket_types_price_check CHECK (price >= 0)');

        Schema::create('saved_events', function (Blueprint $table) {
            $table->string('user_id', 40);
            $table->string('event_id', 40);
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['user_id', 'event_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });

        Schema::create('event_requests', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('event_id', 40);
            $table->string('user_id', 40);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('status', 20)->default('pending');
            $table->string('decided_by_user_id', 40)->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->index(['event_id', 'status']);
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        DB::statement("CREATE UNIQUE INDEX event_requests_active_unique ON event_requests (event_id, user_id) WHERE status IN ('pending','approved')");

        Schema::create('promo_codes', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('code', 40);
            $table->string('event_id', 40)->nullable();
            $table->string('type', 10);
            $table->bigInteger('value');
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->jsonb('applies_to_ticket_type_ids')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX promo_codes_event_code ON promo_codes (event_id, upper(code)) WHERE event_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX promo_codes_global_code ON promo_codes (upper(code)) WHERE event_id IS NULL');
    }

    public function down(): void
    {
        foreach (['promo_codes', 'event_requests', 'saved_events', 'ticket_types', 'sub_events', 'event_schedules',
            'events', 'planner_menus', 'hall_layouts', 'event_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
