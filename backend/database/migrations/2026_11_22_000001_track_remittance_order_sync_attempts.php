<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remittance_orders', function (Blueprint $table): void {
            $table->timestamp('provider_sync_attempted_at')->nullable()->index()
                ->comment('最近一次主动查单尝试时间，成功与失败均记录');
        });
    }

    public function down(): void
    {
        Schema::table('remittance_orders', function (Blueprint $table): void {
            $table->dropIndex(['provider_sync_attempted_at']);
            $table->dropColumn('provider_sync_attempted_at');
        });
    }
};
