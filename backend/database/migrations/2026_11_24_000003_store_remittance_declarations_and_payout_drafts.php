<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 用途声明及收款资料均由模型加密，不与已核验账户混存。 */
    public function up(): void
    {
        foreach (['remittance_quote_records', 'remittance_orders'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->text('declaration')->nullable();
            });
        }
        Schema::create('member_payout_account_drafts', function (Blueprint $table): void {
            $table->uuid('reference')->primary();
            $table->foreignId('member_id')->constrained('users');
            $table->string('payout_method', 10);
            $table->text('details');
            $table->uuid('revision');
            $table->timestamps();
            $table->index(['member_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('member_payout_account_drafts')->exists()
            || DB::table('remittance_quote_records')->whereNotNull('declaration')->exists()
            || DB::table('remittance_orders')->whereNotNull('declaration')->exists()) {
            throw new RuntimeException('存在已保存的用途声明或收款资料，不能无损回退此迁移。');
        }
        Schema::dropIfExists('member_payout_account_drafts');
        foreach (['remittance_quote_records', 'remittance_orders'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('declaration'));
        }
    }
};
