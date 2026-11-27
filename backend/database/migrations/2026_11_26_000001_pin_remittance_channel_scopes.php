<?php

use App\Service\Remittance\RemittanceChannels;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['remittance_quote_records', 'remittance_orders', 'yaband_remit_members', 'yaband_material_requests'];

    public function up(): void
    {
        $scope = app(RemittanceChannels::class)->yabandScope();
        if ($scope === 'yaband:unconfigured' && (DB::table('remittance_orders')->whereNotNull('provider_trade_id')->exists()
            || DB::table('yaband_remit_members')->whereNotNull('uid')->exists()
            || DB::table('yaband_material_requests')->exists())) {
            throw new RuntimeException('已有上游业务数据，迁移前须配置原有雅本地址及商户编号。');
        }
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('channel_scope', 80)->nullable();
            });
            DB::table($tableName)->update(['channel_scope' => $scope]);
        }
    }

    public function down(): void
    {
        $scope = app(RemittanceChannels::class)->yabandScope();
        foreach (self::TABLES as $table) {
            if (DB::table($table)->whereNotNull('channel_scope')->where('channel_scope', '!=', $scope)->exists()) {
                throw new RuntimeException('已保存其他渠道数据，禁止丢失渠道归属。');
            }
        }
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('channel_scope'));
        }
    }
};
