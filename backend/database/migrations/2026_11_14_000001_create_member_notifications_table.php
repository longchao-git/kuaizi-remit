<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_id')->unique();
            $table->string('title', 80);
            $table->string('body', 500);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['member_id', 'id']);
            $table->index(['member_id', 'read_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_notifications');
    }
};
