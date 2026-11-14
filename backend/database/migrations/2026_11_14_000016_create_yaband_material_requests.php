<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yaband_material_requests', function (Blueprint $table): void {
            $table->uuid('reference')->primary();
            $table->foreignId('member_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('order_reference')->nullable()->constrained('remittance_orders', 'reference')->restrictOnDelete();
            $table->string('question_id', 200)->unique();
            $table->string('instruction', 500);
            $table->timestamp('deadline_at');
            $table->string('status', 4)->default('6001');
            $table->uuid('submission_id')->nullable();
            $table->string('submission_hash', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yaband_material_requests');
    }
};
