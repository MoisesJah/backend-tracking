<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('stores');
            $table->timestamp('from_date')->nullable();
            $table->timestamp('to_date')->nullable();
            $table->unsignedInteger('total_orders')->default(0);
            $table->unsignedInteger('synced_orders')->default(0);
            $table->json('failed_stores')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_sync_runs');
    }
};
