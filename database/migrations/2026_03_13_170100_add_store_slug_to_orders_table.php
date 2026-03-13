<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('store_slug')->default('legacy')->index();
            $table->dropUnique('orders_external_id_unique');
            $table->unique(['store_slug', 'external_id'], 'orders_store_slug_external_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_store_slug_external_id_unique');
            $table->unique('external_id', 'orders_external_id_unique');
            $table->dropColumn('store_slug');
        });
    }
};
