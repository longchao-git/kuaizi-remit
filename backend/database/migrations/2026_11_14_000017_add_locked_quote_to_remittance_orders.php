<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remittance_orders', function (Blueprint $table): void {
            $table->string('provider_trade_id', 64)->nullable()->change();
            $table->foreignUuid('quote_reference')->nullable()->unique()->constrained('remittance_quote_records', 'reference')->restrictOnDelete();
            $table->json('quote_snapshot')->nullable();
            $table->uuid('payment_request_id')->nullable()->unique();
            $table->string('payment_request_hash', 64)->nullable();
            $table->text('payment_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('remittance_orders', function (Blueprint $table): void {
            $table->dropForeign(['quote_reference']);
            $table->dropUnique(['quote_reference']);
            $table->dropUnique(['payment_request_id']);
            $table->dropColumn(['quote_reference', 'quote_snapshot', 'payment_request_id', 'payment_request_hash', 'payment_url']);
        });
        // 已建立的待付款订单可能没有上游交易号，保留字段可空以支持无损回滚。
    }
};
