<?php

namespace App\Console\Commands;

use App\Support\DemoAccounts;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seed or remove the demo dataset. Production needs DEMO_DATA_IN_PRODUCTION=true and
 * DEMO_DATA_PASSWORD to seed; --remove works everywhere.
 *
 *   php artisan wedpluz:demo            seed (skips if already present)
 *   php artisan wedpluz:demo --reset    remove demo data, then seed again
 *   php artisan wedpluz:demo --remove   remove demo data only
 *
 * Removal touches only rows owned by @demo.wedpluz.test accounts (and the
 * events, orders, ledger postings and settlements derived from them), so real
 * local accounts and reference data are kept.
 */
class DemoDataCommand extends Command
{
    protected $signature = 'wedpluz:demo {--reset : Remove existing demo data and seed again} {--remove : Remove demo data only}';

    protected $description = 'Seed (or remove) realistic demo data for local frontend testing';

    public function handle(): int
    {
        // Removing is always allowed (it only touches @demo.wedpluz.test data), so a server can be
        // cleaned up before launch even after DEMO_DATA_IN_PRODUCTION is switched off again.
        if (! $this->option('remove') && ! DemoAccounts::seedingAllowed()) {
            $this->error('Demo data is disabled in production. Set DEMO_DATA_IN_PRODUCTION=true and DEMO_DATA_PASSWORD in .env to allow it.');

            return self::FAILURE;
        }

        if ($this->option('reset') || $this->option('remove')) {
            $this->remove();
            if ($this->option('remove')) {
                return self::SUCCESS;
            }
        }

        $this->call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);

        return self::SUCCESS;
    }

    private function remove(): void
    {
        DB::transaction(function () {
            $users = DB::table('users')->where('email', 'like', '%@'.DemoDataSeeder::DOMAIN)->pluck('id');
            if ($users->isEmpty() && ! DB::table('admin_users')->where('email', 'like', '%@'.DemoDataSeeder::DOMAIN)->exists()) {
                $this->info('No demo data found.');

                return;
            }
            $events = DB::table('events')->whereIn('organizer_user_id', $users)->pluck('id');
            $orders = DB::table('orders')->whereIn('user_id', $users)->orWhereIn('event_id', $events)->pluck('id');
            $wallets = DB::table('wallets')->whereIn('user_id', $users)->pluck('id');
            $walletTx = DB::table('wallet_transactions')->whereIn('wallet_id', $wallets)->pluck('id');
            $vendors = DB::table('vendor_profiles')->whereIn('user_id', $users)->pluck('id');
            $works = DB::table('vendor_works')->whereIn('vendor_profile_id', $vendors)->pluck('id');
            $settlements = DB::table('settlements')->whereIn('organizer_user_id', $users)->orWhereIn('event_id', $events)->pluck('id');

            // Money trail first (no cascading FKs by design).
            DB::table('ledger_entries')->whereIn('reference_id', $orders->merge($walletTx)->merge($settlements))->delete();
            DB::table('ledger_entries')->where(function ($q) use ($wallets, $users) {
                foreach ($wallets as $id) {
                    $q->orWhere('account', 'wallet:'.$id)->orWhere('account', 'wallet_pending:'.$id);
                }
                foreach ($users as $id) {
                    $q->orWhere('account', 'organizer_payable:'.$id);
                }
            })->delete();
            DB::table('payments')->whereIn('payable_id', $orders->merge($walletTx))->delete();
            DB::table('refunds')->whereIn('order_id', $orders)->delete();
            DB::table('disputes')->whereIn('order_id', $orders)->delete();
            DB::table('payout_settlements')->whereIn('settlement_id', $settlements)->delete();
            DB::table('settlements')->whereIn('id', $settlements)->delete();
            DB::table('payouts')->whereIn('organizer_user_id', $users)->delete();
            DB::table('tickets')->whereIn('event_id', $events)->orWhereIn('order_id', $orders)->delete();
            DB::table('orders')->whereIn('id', $orders)->delete();

            // Polymorphic / nullable references.
            DB::table('media')->where('owner_type', 'work')->whereIn('owner_id', $works)->delete();
            DB::table('support_messages')->whereIn('ticket_id', DB::table('support_tickets')->whereIn('user_id', $users)->pluck('id'))->delete();
            DB::table('support_tickets')->whereIn('user_id', $users)->delete();
            DB::table('content_reports')->whereIn('reported_user_id', $users)->delete();
            DB::table('banners')->where('image_url', 'like', '%/demo/banners/%')->delete();
            DB::table('notifications')->whereIn('actor_user_id', $users)->delete();

            DB::table('events')->whereIn('id', $events)->delete();
            DB::table('users')->whereIn('id', $users)->delete(); // cascades profiles, wallets, communities, messages…
            DB::table('conversations')->where('type', 'direct')->whereNotExists(fn ($q) => $q->from('conversation_participants')->whereColumn('conversation_participants.conversation_id', 'conversations.id'))->delete();
            DB::table('admin_users')->where('email', 'like', '%@'.DemoDataSeeder::DOMAIN)->delete();

            $this->info('Removed demo data: '.$users->count().' users, '.$events->count().' events, '.$orders->count().' orders.');
        });
    }
}
