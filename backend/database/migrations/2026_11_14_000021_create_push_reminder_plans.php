<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_reminder_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('title', 80);
            $table->string('body', 500);
            $table->string('send_time', 5);
            $table->string('timezone', 64);
            $table->string('status', 12);
            $table->timestamp('active_from')->nullable();
            $table->timestamps();
        });

        Schema::create('push_reminder_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('push_reminder_plans')->restrictOnDelete();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('notice_id')->unique()->constrained('member_notifications')->cascadeOnDelete();
            $table->string('push_status', 16)->default('preparing');
            $table->timestamp('created_at');
            $table->unique(['plan_id', 'member_id']);
        });

        Schema::create('push_reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('send_id')->constrained('push_reminder_sends')->cascadeOnDelete();
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('token_id');
            $table->string('status', 16);
            $table->timestamps();
            $table->unique(['send_id', 'device_id', 'token_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_reminder_deliveries');
        Schema::dropIfExists('push_reminder_sends');
        Schema::dropIfExists('push_reminder_plans');
    }
};
