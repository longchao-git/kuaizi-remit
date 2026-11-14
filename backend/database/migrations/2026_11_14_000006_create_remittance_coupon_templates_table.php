<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remittance_coupon_templates', function (Blueprint $table) {
            $table->id();
            $table->string('title', 80);
            $table->unsignedSmallInteger('discount_bps');
            $table->unsignedSmallInteger('valid_days');
            $table->string('payout_method', 12)->nullable();
            $table->boolean('enabled');
            $table->timestamps();
        });
        Schema::table('remittance_coupons', function (Blueprint $table) {
            $table->foreignId('template_id')->nullable()->constrained('remittance_coupon_templates');
            $table->string('payout_method', 12)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('remittance_coupons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_id');
            $table->dropColumn('payout_method');
        });
        Schema::dropIfExists('remittance_coupon_templates');
    }
};
