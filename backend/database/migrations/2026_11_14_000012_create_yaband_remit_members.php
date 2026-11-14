<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yaband_remit_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->uuid('create_request_id')->unique();
            $table->string('uid', 64)->nullable()->unique();
            $table->json('statuses')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yaband_remit_members');
    }
};
