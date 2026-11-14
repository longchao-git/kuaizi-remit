<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('client_id');
            $table->string('body', 500);
            $table->timestamp('created_at');
            $table->unique(['member_id', 'sender_id', 'client_id']);
            $table->index(['member_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
    }
};
