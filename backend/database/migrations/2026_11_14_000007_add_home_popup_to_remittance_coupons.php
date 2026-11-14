<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remittance_coupons', function (Blueprint $table) {
            $table->boolean('home_popup')->default(false);
            $table->index(['member_id', 'home_popup', 'claimed_at', 'id'], 'remittance_coupons_home_pending');
        });
    }

    public function down(): void
    {
        Schema::table('remittance_coupons', function (Blueprint $table) {
            $table->dropIndex('remittance_coupons_home_pending');
            $table->dropColumn('home_popup');
        });
    }
};
