<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_read_cursors', function (Blueprint $table) {
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->string('side', 6);
            $table->unsignedBigInteger('last_read_id');
            $table->primary(['member_id', 'side']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_read_cursors');
    }
};
