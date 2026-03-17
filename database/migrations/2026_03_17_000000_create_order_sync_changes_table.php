<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_sync_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sync_run_id')->constrained('order_sync_runs')->cascadeOnDelete();
            $table->string('field');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('action')->default('updated'); // created, updated
            $table->string('source')->default('woo_sync');
            $table->timestamps();

            $table->index(['order_id', 'sync_run_id']);
            $table->index(['sync_run_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_sync_changes');
    }
};
