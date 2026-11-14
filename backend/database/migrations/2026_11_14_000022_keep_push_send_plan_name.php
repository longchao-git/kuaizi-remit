<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_reminder_sends', function (Blueprint $table) {
            $table->string('plan_name', 80)->nullable();
        });

        DB::table('push_reminder_sends')->update([
            'plan_name' => DB::table('push_reminder_plans')->select('name')
                ->whereColumn('push_reminder_plans.id', 'push_reminder_sends.plan_id'),
        ]);

        Schema::table('push_reminder_sends', function (Blueprint $table) {
            $table->string('plan_name', 80)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('push_reminder_sends', function (Blueprint $table) {
            $table->dropColumn('plan_name');
        });
    }
};
