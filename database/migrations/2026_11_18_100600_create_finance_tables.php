<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wallets on an append-only double-entry ledger, bank accounts, commission
 * rules, settlements and payouts (four-eyes), reconciliation, plans and
 * subscriptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('user_id', 40)->unique();
            $table->char('currency', 3)->default('NGN');
            $table->boolean('is_active')->default(false);
            $table->string('pin_hash')->nullable();
            $table->unsignedSmallInteger('pin_failed_attempts')->default(0);
            $table->timestampTz('pin_locked_until')->nullable();
            // Cache only: updated in the same DB transaction as ledger entries.
            $table->bigInteger('cached_balance')->default(0);
            $table->bigInteger('cached_pending_balance')->default(0);
            $table->bigInteger('daily_withdrawal_limit')->default(50000000);
            $table->timestampTz('activated_at')->nullable();
            $table->timestampsTz();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_balance_check CHECK (cached_balance >= 0)');

        // Append-only: entries per transaction_id sum to zero (debits = credits).
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id', 40);
            $table->string('account', 120);
            $table->string('direction', 6);
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('reference_type', 60)->nullable();
            $table->string('reference_id', 40)->nullable();
            $table->string('description', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['account', 'created_at']);
            $table->index('transaction_id');
            $table->index(['reference_type', 'reference_id']);
        });
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_direction_check CHECK (direction IN ('debit','credit'))");
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_check CHECK (amount > 0)');

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('reference', 30)->unique();
            $table->string('wallet_id', 40);
            $table->string('type', 30);
            $table->string('direction', 6);
            $table->bigInteger('amount');
            $table->bigInteger('fee')->default(0);
            $table->char('currency', 3);
            $table->string('status', 20)->default('pending');
            $table->string('description', 255);
            $table->string('counterparty', 255)->nullable();
            $table->string('provider', 20)->nullable();
            $table->string('provider_reference', 191)->nullable();
            $table->string('bank_account_id', 40)->nullable();
            $table->string('related_type', 60)->nullable();
            $table->string('related_id', 40)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['wallet_id', 'created_at']);
            $table->index(['type', 'status']);
            $table->index('provider_reference');
            $table->unique(['wallet_id', 'idempotency_key']);
            $table->foreign('wallet_id')->references('id')->on('wallets')->cascadeOnDelete();
        });

        Schema::create('banks', function (Blueprint $table) {
            $table->string('code', 20)->primary();
            $table->string('name', 200);
            $table->jsonb('provider_codes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('user_id', 40);
            $table->string('bank_code', 20);
            $table->string('bank_name', 200);
            $table->text('account_number_encrypted');
            $table->string('account_number_hash', 64);
            $table->string('account_last4', 4);
            $table->string('account_name', 200);
            $table->boolean('is_default')->default(false);
            $table->string('provider_recipient_code', 191)->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX bank_accounts_unique ON bank_accounts (user_id, bank_code, account_number_hash) WHERE deleted_at IS NULL');

        Schema::create('commission_rules', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('scope', 20);
            $table->string('scope_id', 40)->nullable();
            $table->string('scope_label', 200);
            $table->decimal('percent', 5, 2);
            $table->bigInteger('fixed_fee')->default(0);
            $table->char('currency', 3)->default('NGN');
            $table->date('effective_from');
            $table->string('updated_by_admin_id', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement("CREATE UNIQUE INDEX commission_rules_active_scope ON commission_rules (scope, coalesce(scope_id, '')) WHERE is_active");

        Schema::create('payouts', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('reference', 30)->unique();
            $table->string('organizer_user_id', 40);
            $table->jsonb('bank_account_snapshot');
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('provider', 20);
            $table->string('provider_reference', 191)->nullable();
            $table->string('status', 20)->default('requested');
            $table->string('requested_by_admin_id', 40)->nullable();
            $table->string('approved_by_admin_id', 40)->nullable();
            $table->string('rejection_reason', 1000)->nullable();
            $table->string('failure_reason', 1000)->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
            $table->foreign('organizer_user_id')->references('id')->on('users');
        });
        DB::statement('ALTER TABLE payouts ADD CONSTRAINT payouts_four_eyes CHECK (approved_by_admin_id IS NULL OR approved_by_admin_id <> requested_by_admin_id)');

        Schema::create('settlements', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('reference', 30)->unique();
            $table->string('organizer_user_id', 40);
            $table->string('event_id', 40);
            $table->bigInteger('gross_sales')->default(0);
            $table->bigInteger('refunds')->default(0);
            $table->bigInteger('platform_commission')->default(0);
            $table->bigInteger('gateway_fees')->default(0);
            $table->bigInteger('promoter_commissions')->default(0);
            $table->bigInteger('adjustments')->default(0);
            $table->bigInteger('net_payable')->default(0);
            $table->char('currency', 3);
            $table->string('status', 20)->default('pending');
            $table->string('hold_reason', 1000)->nullable();
            $table->string('pending_reason', 500)->nullable();
            $table->string('failure_reason', 1000)->nullable();
            $table->timestampTz('eligible_at');
            $table->timestampTz('paid_at')->nullable();
            $table->string('payout_id', 40)->nullable();
            $table->timestampsTz();
            $table->unique(['event_id', 'organizer_user_id']);
            $table->index(['status', 'eligible_at']);
            $table->foreign('organizer_user_id')->references('id')->on('users');
            $table->foreign('event_id')->references('id')->on('events');
            $table->foreign('payout_id')->references('id')->on('payouts')->nullOnDelete();
        });

        Schema::create('payout_settlements', function (Blueprint $table) {
            $table->string('payout_id', 40);
            $table->string('settlement_id', 40);
            $table->primary(['payout_id', 'settlement_id']);
            $table->foreign('payout_id')->references('id')->on('payouts')->cascadeOnDelete();
            $table->foreign('settlement_id')->references('id')->on('settlements')->cascadeOnDelete();
        });

        Schema::create('reconciliation_records', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('payment_id', 40)->nullable();
            $table->string('provider', 20);
            $table->jsonb('provider_report_line')->nullable();
            $table->string('status', 20);
            $table->string('note', 1000)->nullable();
            $table->string('resolved_by_admin_id', 40)->nullable();
            $table->timestampsTz();
            $table->index('payment_id');
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('tier', 20)->unique();
            $table->string('name', 60);
            $table->string('tagline', 200);
            $table->bigInteger('price_monthly');
            $table->bigInteger('price_yearly');
            $table->char('currency', 3)->default('NGN');
            $table->jsonb('features');
            $table->jsonb('limits')->nullable();
            $table->boolean('is_popular')->default(false);
            $table->string('inherits_from', 40)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('user_id', 40);
            $table->string('plan_id', 40);
            $table->string('interval', 10);
            $table->string('status', 20);
            $table->string('pending_plan_id', 40)->nullable();
            $table->string('pending_interval', 10)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('renews_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('past_due_at')->nullable();
            $table->string('provider', 20)->nullable();
            $table->string('provider_subscription_id', 191)->nullable();
            $table->timestampsTz();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('plan_id')->references('id')->on('plans');
        });
        DB::statement("CREATE UNIQUE INDEX subscriptions_one_active ON subscriptions (user_id) WHERE status IN ('active','trialing','past_due')");

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('reference', 30)->unique();
            $table->string('user_id', 40);
            $table->string('subscription_id', 40)->nullable();
            $table->string('plan_id', 40);
            $table->string('interval', 10);
            $table->string('payment_id', 40)->nullable();
            $table->string('payment_method', 20);
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 20)->default('pending');
            $table->string('idempotency_key', 191)->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->string('receipt_url', 2048)->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'created_at']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('planner_chats', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('user_id', 40);
            $table->string('title', 200);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['user_id', 'updated_at']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('planner_chat_messages', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('chat_id', 40);
            $table->string('role', 20);
            $table->text('content');
            $table->jsonb('attachments')->nullable();
            $table->string('generation_id', 40)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['chat_id', 'created_at']);
            $table->foreign('chat_id')->references('id')->on('planner_chats')->cascadeOnDelete();
        });

        Schema::create('planner_generations', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('user_id', 40);
            $table->string('type', 30);
            $table->text('prompt');
            $table->string('name', 200)->nullable();
            $table->string('status', 20)->default('queued');
            $table->jsonb('result_urls');
            $table->string('provider', 30)->nullable();
            $table->unsignedInteger('cost_units')->default(0);
            $table->string('event_id', 40)->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['user_id', 'created_at']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['planner_generations', 'planner_chat_messages', 'planner_chats', 'subscription_payments', 'subscriptions', 'plans',
            'reconciliation_records', 'payout_settlements', 'settlements', 'payouts', 'commission_rules', 'bank_accounts', 'banks',
            'wallet_transactions', 'ledger_entries', 'wallets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
