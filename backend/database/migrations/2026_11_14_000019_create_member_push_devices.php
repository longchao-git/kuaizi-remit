<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_push_devices', function (Blueprint $table): void {
            $table->id();
            $table->string('client_hash', 64)->unique();
            $table->foreignId('token_id')->unique()->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->text('client_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_push_devices');
    }
};
