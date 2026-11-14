<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remittance_coupons', function (Blueprint $table): void {
            $table->foreignUuid('order_reference')->nullable()->unique()->constrained('remittance_orders', 'reference')->restrictOnDelete();
            $table->timestamp('redeemed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('remittance_coupons', function (Blueprint $table): void {
            $table->dropForeign(['order_reference']);
            $table->dropUnique(['order_reference']);
            $table->dropColumn(['order_reference', 'redeemed_at']);
        });
    }
};
