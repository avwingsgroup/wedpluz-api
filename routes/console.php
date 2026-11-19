<?php

use App\Services\Platform\HealthCheck;
use App\Services\Platform\ScheduledTasks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduled tasks. Run `php artisan schedule:work` (dev) or a cron entry
| `* * * * * php artisan schedule:run` (production) on exactly one host;
| withoutOverlapping + onOneServer use the Redis cache lock.
*/

$tasks = fn () => app(ScheduledTasks::class);

Artisan::command('wedpluz:expire-orders', fn () => $this->info('Expired: '.$tasks()->expireOrders()))->purpose('Expire unpaid orders and release holds');
Artisan::command('wedpluz:complete-events', fn () => $this->info('Completed: '.$tasks()->completeEvents()))->purpose('Complete ended events and open settlements');
Artisan::command('wedpluz:promote-settlements', fn () => $this->info('Ready: '.$tasks()->promoteSettlements()))->purpose('Move eligible settlements to ready');
Artisan::command('wedpluz:renew-subscriptions', fn () => $tasks()->renewSubscriptions())->purpose('Renew, downgrade or expire subscriptions');
Artisan::command('wedpluz:dispatch-campaigns', fn () => $this->info('Started: '.$tasks()->dispatchScheduledCampaigns()))->purpose('Start scheduled campaigns');
Artisan::command('wedpluz:event-reminders', fn () => $this->info('Reminders: '.$tasks()->sendEventReminders()))->purpose('Send 24h and 2h event reminders');
Artisan::command('wedpluz:process-deletions', fn () => $this->info('Deleted: '.$tasks()->processAccountDeletions()))->purpose('Anonymise accounts past the grace period');
Artisan::command('wedpluz:archive-communities', fn () => $this->info('Archived: '.$tasks()->archiveCommunities()))->purpose('Archive communities of cancelled events');
Artisan::command('wedpluz:reconcile', fn () => $this->info(json_encode($tasks()->reconcilePayments())))->purpose('Reconcile payments against provider records');
Artisan::command('wedpluz:purge', fn () => $this->info(json_encode($tasks()->purge())))->purpose('Apply data-retention rules');
Artisan::command('wedpluz:warm-metrics', fn () => $tasks()->warmMetrics())->purpose('Warm admin dashboard caches');

// Scheduler liveness for /health.
Schedule::call(fn () => HealthCheck::beatScheduler())->everyMinute()->name('health:scheduler-heartbeat');
Schedule::command('wedpluz:expire-orders')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('wedpluz:dispatch-campaigns')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('wedpluz:complete-events')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('wedpluz:event-reminders')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('wedpluz:warm-metrics')->everyTenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('wedpluz:promote-settlements')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('wedpluz:renew-subscriptions')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('wedpluz:archive-communities')->hourly()->onOneServer();
Schedule::command('wedpluz:reconcile')->dailyAt('03:00')->timezone('Africa/Lagos')->withoutOverlapping()->onOneServer();
Schedule::command('wedpluz:process-deletions')->dailyAt('02:00')->timezone('Africa/Lagos')->onOneServer();
Schedule::command('wedpluz:purge')->dailyAt('04:00')->timezone('Africa/Lagos')->onOneServer();
Schedule::command('queue:prune-failed --hours=720')->daily()->onOneServer();
