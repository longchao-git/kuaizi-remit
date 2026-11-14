<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('client_id');
            $table->string('subject', 80);
            $table->string('body', 500);
            $table->string('status', 20);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->unique(['member_id', 'client_id']);
            $table->index(['member_id', 'id']);
            $table->index(['status', 'id']);
        });
        Schema::create('support_ticket_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_id');
            $table->unsignedInteger('version');
            $table->string('status', 20);
            $table->string('reply', 500);
            $table->timestamp('created_at');
            $table->unique(['ticket_id', 'version']);
            $table->unique(['ticket_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_updates');
        Schema::dropIfExists('support_tickets');
    }
};
