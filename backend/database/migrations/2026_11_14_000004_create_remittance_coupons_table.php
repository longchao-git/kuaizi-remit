<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remittance_coupons', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('member_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 32)->nullable()->unique();
            $table->string('title', 80);
            $table->unsignedSmallInteger('discount_bps');
            $table->char('issue_fingerprint', 64);
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remittance_coupons');
    }
};
