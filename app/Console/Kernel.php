<?php

namespace App\Console;

use App\Jobs\CancelExpiredOrderErrorsJob;
use App\Jobs\SyncWooOrdersJob;
use App\Models\OrderSyncRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Sync WooCommerce orders every 5 minutes
        $schedule->call(function (): void {
            $run = OrderSyncRun::create([
                'status' => 'pending',
                'stores' => config('woocommerce.stores') ? array_keys(config('woocommerce.stores')) : [],
                'requested_by' => null,
            ]);

            SyncWooOrdersJob::dispatch($run->id);
        })
            ->everyFiveMinutes()
            ->name('sync-woo-orders-automatic')
            ->description('Automatically sync WooCommerce orders every 5 minutes');

        // Run job to cancel orders with expired errors
        // Every hour, check for orders in error status for more than 1 day
        $schedule->job(CancelExpiredOrderErrorsJob::class)
            ->hourly()
            ->name('cancel-expired-order-errors')
            ->description('Cancel orders with errors that have exceeded 1 day');

        // Optionally, you can also run it at specific times:
        // $schedule->job(CancelExpiredOrderErrorsJob::class)
        //     ->daily()
        //     ->at('03:00');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
}
