<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_notifications', function (Blueprint $table): void {
            $table->foreignUuid('order_reference')->nullable()->constrained('remittance_orders', 'reference')->nullOnDelete();
        });

        // 旧版仅在这三种系统消息中使用固定正文，关联前再次核对订单归属。
        $prefix = '请在汇款订单查看详情。订单编号 ';
        DB::table('member_notifications')->select('id', 'member_id', 'body')
            ->whereNull('sender_id')->whereNull('support_ticket_id')
            ->whereIn('title', ['已确认收到汇款付款', '汇款已下发', '汇款订单已退款'])
            ->where('body', 'like', $prefix.'%')
            ->chunkById(200, function (Collection $notices) use ($prefix): void {
                $references = $notices->mapWithKeys(fn ($notice) => [$notice->id => substr($notice->body, strlen($prefix))])
                    ->filter(fn (string $reference) => Str::isUuid($reference));
                $owners = DB::table('remittance_orders')->whereIn('reference', $references->values())->pluck('member_id', 'reference');

                foreach ($notices as $notice) {
                    $reference = $references->get($notice->id);
                    if ($reference !== null && (string) $owners->get($reference) === (string) $notice->member_id) {
                        DB::table('member_notifications')->where('id', $notice->id)->whereNull('order_reference')
                            ->update(['order_reference' => $reference]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('member_notifications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('order_reference');
        });
    }
};
