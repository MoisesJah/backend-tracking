<?php

use App\Jobs\CancelExpiredOrderErrorsJob;
use App\Jobs\SyncWooOrdersJob;
use App\Models\OrderSyncRun;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    $run = OrderSyncRun::query()->create([
        'status' => 'pending',
        'stores' => config('woocommerce.stores') ? array_keys(config('woocommerce.stores')) : [],
        'requested_by' => null,
    ]);

    SyncWooOrdersJob::dispatch($run->id);
})
    ->everyFiveMinutes()
    ->name('sync-woo-orders-automatic')
    ->description('Automatically sync WooCommerce orders every 5 minutes');

Schedule::job(CancelExpiredOrderErrorsJob::class)
    ->hourly()
    ->name('cancel-expired-order-errors')
    ->description('Cancel orders with errors that have exceeded 1 day');
