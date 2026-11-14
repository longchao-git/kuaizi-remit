<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('platform_role', 24)->nullable();
        });
        Schema::create('platform_role_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operator_name');
            $table->string('target_name');
            $table->string('before_role', 24)->nullable();
            $table->string('after_role', 24);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_role_audits');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('platform_role');
        });
    }
};
