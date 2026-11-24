<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 保存完整问卷正文、创建身份时的外部编号及消息跳转，不回填猜测的历史身份。 */
    public function up(): void
    {
        Schema::table('yaband_material_requests', function (Blueprint $table): void {
            $table->text('instruction')->change();
        });
        Schema::table('yaband_remit_members', function (Blueprint $table): void {
            $table->string('external_uid', 64)->nullable();
        });
        Schema::table('member_notifications', function (Blueprint $table): void {
            $table->uuid('material_reference')->nullable();
            $table->foreign('material_reference')->references('reference')->on('yaband_material_requests')->nullOnDelete();
        });
    }

    /** 长正文不能静默截断；有不兼容数据时在任何回退操作前停止。 */
    public function down(): void
    {
        foreach (DB::table('yaband_material_requests')->select('instruction')->orderBy('reference')->lazy() as $row) {
            if (mb_strlen($row->instruction, 'UTF-8') > 500) {
                throw new RuntimeException('存在超过500字符的补件正文，不能无损回退此迁移。');
            }
        }
        Schema::table('member_notifications', function (Blueprint $table): void {
            $table->dropForeign(['material_reference']);
            $table->dropColumn('material_reference');
        });
        Schema::table('yaband_remit_members', function (Blueprint $table): void {
            $table->dropColumn('external_uid');
        });
        Schema::table('yaband_material_requests', function (Blueprint $table): void {
            $table->string('instruction', 500)->change();
        });
    }
};
