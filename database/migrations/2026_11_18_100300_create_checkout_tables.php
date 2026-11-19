<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quotes and holds, orders, payments, tickets, check-ins, refunds, transfers
 * and disputes. Money is bigint minor units with an ISO-4217 currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_quotes', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('user_id', 40);
            $table->string('event_id', 40);
            $table->jsonb('lines');
            $table->jsonb('food_lines');
            $table->string('promo_code_id', 40)->nullable();
            $table->string('promo_code', 40)->nullable();
            $table->string('referral_enrollment_id', 40)->nullable();
            $table->bigInteger('subtotal');
            $table->bigInteger('ticket_subtotal');
            $table->bigInteger('service_fee');
            $table->bigInteger('discount');
            $table->bigInteger('total');
            $table->char('currency', 3);
            $table->boolean('requires_seat_selection')->default(false);
            $table->boolean('requires_menu_selection')->default(false);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'created_at']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });

        Schema::create('inventory_holds', function (Blueprint $table) {
            $table->id();
            $table->string('quote_id', 40);
            $table->string('order_id', 40)->nullable();
            $table->string('ticket_type_id', 40);
            $table->unsignedInteger('quantity');
            $table->timestampTz('expires_at');
            $table->timestampTz('released_at')->nullable();
            $table->timestampTz('converted_at')->nullable();
            $table->timestampsTz();
            $table->index(['ticket_type_id', 'expires_at']);
            $table->index('quote_id');
            $table->foreign('quote_id')->references('id')->on('checkout_quotes')->cascadeOnDelete();
            $table->foreign('ticket_type_id')->references('id')->on('ticket_types')->cascadeOnDelete();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('reference', 30)->unique();
            $table->string('user_id', 40);
            $table->string('event_id', 40);
            $table->string('quote_id', 40)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('payment_method', 20);
            $table->string('provider', 20)->default('none');
            $table->jsonb('attendee');
            $table->jsonb('additional_guests');
            $table->bigInteger('subtotal');
            $table->bigInteger('ticket_subtotal');
            $table->bigInteger('service_fee');
            $table->bigInteger('discount');
            $table->bigInteger('total');
            $table->bigInteger('refunded_amount')->default(0);
            $table->char('currency', 3);
            $table->string('promo_code_id', 40)->nullable();
            $table->jsonb('commission_rule_snapshot')->nullable();
            $table->string('promoter_enrollment_id', 40)->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->string('return_url', 2048)->nullable();
            $table->string('source', 10)->default('web');
            $table->string('cancellation_reason', 60)->nullable();
            $table->string('cancellation_note', 1000)->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['event_id', 'status']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('event_id')->references('id')->on('events');
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->string('order_id', 40);
            $table->string('ticket_type_id', 40);
            $table->string('name_snapshot', 120);
            $table->bigInteger('unit_price');
            $table->unsignedInteger('quantity');
            $table->bigInteger('subtotal');
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('ticket_type_id')->references('id')->on('ticket_types');
        });

        Schema::create('order_food_lines', function (Blueprint $table) {
            $table->id();
            $table->string('order_id', 40);
            $table->string('menu_item_id', 40);
            $table->string('name_snapshot', 200);
            $table->bigInteger('unit_price');
            $table->unsignedInteger('quantity');
            $table->bigInteger('subtotal');
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });

        Schema::create('seat_reservations', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('order_id', 40);
            $table->string('schedule_id', 40);
            $table->string('seat_id', 80);
            $table->string('table_label', 60);
            $table->string('seat_label', 60);
            $table->string('status', 20)->default('held');
            $table->timestampsTz();
            $table->index('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('schedule_id')->references('id')->on('event_schedules')->cascadeOnDelete();
        });
        DB::statement("CREATE UNIQUE INDEX seat_reservations_active_unique ON seat_reservations (schedule_id, seat_id) WHERE status IN ('held','confirmed')");

        Schema::create('payments', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('payable_type', 60);
            $table->string('payable_id', 40);
            $table->string('user_id', 40)->nullable();
            $table->string('provider', 20);
            $table->string('provider_reference', 191);
            $table->string('provider_transaction_id', 191)->nullable();
            $table->string('purpose', 20);
            $table->bigInteger('amount');
            $table->bigInteger('fee')->default(0);
            $table->bigInteger('refunded_amount')->default(0);
            $table->char('currency', 3);
            $table->string('status', 20)->default('initialized');
            $table->string('reconciliation_status', 20)->default('pending');
            $table->string('reconciliation_note', 1000)->nullable();
            $table->string('authorization_url', 2048)->nullable();
            $table->jsonb('client_params')->nullable();
            $table->jsonb('raw_payload')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('settled_at')->nullable();
            $table->timestampsTz();
            $table->unique(['provider', 'provider_reference']);
            $table->index(['payable_type', 'payable_id']);
            $table->index(['status', 'created_at']);
            $table->index(['reconciliation_status', 'created_at']);
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('code', 20)->unique();
            $table->string('order_id', 40);
            $table->string('event_id', 40);
            $table->string('schedule_id', 40)->nullable();
            $table->string('ticket_type_id', 40);
            $table->string('holder_user_id', 40)->nullable();
            $table->string('holder_name', 200);
            $table->string('holder_email')->nullable();
            $table->boolean('is_additional_guest')->default(false);
            $table->unsignedSmallInteger('qr_secret_version')->default(1);
            $table->string('status', 20)->default('valid');
            $table->timestampTz('checked_in_at')->nullable();
            $table->string('checked_in_by_user_id', 40)->nullable();
            $table->string('seat_reservation_id', 40)->nullable();
            $table->string('transferred_to_ticket_id', 40)->nullable();
            $table->string('transferred_to_user_id', 40)->nullable();
            $table->timestampsTz();
            $table->index(['event_id', 'status']);
            $table->index(['holder_user_id', 'created_at']);
            $table->index('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('event_id')->references('id')->on('events');
            $table->foreign('ticket_type_id')->references('id')->on('ticket_types');
        });

        Schema::create('check_ins', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('ticket_id', 40)->nullable();
            $table->string('event_id', 40);
            $table->string('code_scanned', 512);
            $table->string('result', 30);
            $table->string('method', 10);
            $table->string('device', 255)->nullable();
            $table->string('scanned_by_user_id', 40);
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['event_id', 'created_at']);
            $table->index(['result', 'created_at']);
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('reference', 30)->unique();
            $table->string('order_id', 40);
            $table->string('payment_id', 40)->nullable();
            $table->bigInteger('amount');
            // Part of `amount` refunded from platform service fees (rest comes from organiser revenue).
            $table->bigInteger('fee_portion')->default(0);
            $table->char('currency', 3);
            $table->string('reason', 1000);
            $table->string('status', 20)->default('requested');
            $table->string('provider', 20);
            $table->string('provider_reference', 191)->nullable();
            $table->string('requested_by_user_id', 40)->nullable();
            $table->string('requested_by_admin_id', 40)->nullable();
            $table->string('decided_by_admin_id', 40)->nullable();
            $table->string('ticket_id', 40)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->boolean('needs_attention')->default(false);
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
            $table->index('order_id');
            $table->index('provider_reference');
            $table->foreign('order_id')->references('id')->on('orders');
        });
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_check CHECK (amount > 0)');

        Schema::create('ticket_transfers', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('ticket_id', 40);
            $table->string('from_user_id', 40);
            $table->string('recipient_email');
            $table->string('message', 500)->nullable();
            $table->string('new_ticket_id', 40)->nullable();
            $table->string('status', 20)->default('completed');
            $table->timestampsTz();
            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
        });

        Schema::create('disputes', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('order_id', 40);
            $table->string('raised_by_user_id', 40)->nullable();
            $table->string('type', 20)->default('other');
            $table->string('status', 30)->default('open');
            $table->text('description');
            $table->string('assigned_admin_id', 40)->nullable();
            $table->string('provider_dispute_id', 191)->nullable()->unique();
            $table->jsonb('notes')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
            $table->foreign('order_id')->references('id')->on('orders');
        });
    }

    public function down(): void
    {
        foreach (['disputes', 'ticket_transfers', 'refunds', 'check_ins', 'tickets', 'payments', 'seat_reservations',
            'order_food_lines', 'order_lines', 'orders', 'inventory_holds', 'checkout_quotes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
