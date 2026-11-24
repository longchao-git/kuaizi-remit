<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 保留审核记录，单独记录注销执行、核实及本地登录停用时间。 */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('member_closed_at')->nullable();
        });
        Schema::table('member_closure_requests', function (Blueprint $table): void {
            $table->string('execution_status', 32)->default('not_started');
            $table->uuid('execution_request_id')->nullable()->unique();
            $table->string('execution_uid', 64)->nullable();
            $table->string('execution_note', 300)->nullable();
            $table->timestamp('execution_started_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_checked_at')->nullable();
            $table->foreignId('last_checked_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    /** 回退仅移除本轮字段，不删除用户、申请、订单或财务记录。 */
    public function down(): void
    {
        Schema::table('member_closure_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('executed_by');
            $table->dropConstrainedForeignId('last_checked_by');
            $table->dropUnique(['execution_request_id']);
            $table->dropColumn(['execution_status', 'execution_request_id', 'execution_uid', 'execution_note', 'execution_started_at', 'executed_at', 'last_checked_at']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('member_closed_at');
        });
    }
};
