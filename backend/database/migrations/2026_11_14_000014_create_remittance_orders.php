<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remittance_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('member_id')->constrained('users')->restrictOnDelete();
            $table->string('provider_trade_id', 64)->unique();
            $table->boolean('white_label');
            $table->unsignedBigInteger('principal_minor');
            $table->string('income_code', 8);
            $table->string('outgoing_code', 8);
            $table->string('refund_code', 8);
            $table->timestamp('provider_checked_at')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remittance_orders');
    }
};
