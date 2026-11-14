<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remittance_reconciliation_batches', function (Blueprint $table) {
            $table->id();
            $table->date('settlement_date')->index();
            $table->string('filename', 180);
            $table->string('digest', 64)->unique();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('remittance_reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('remittance_reconciliation_batches')->restrictOnDelete();
            $table->uuid('order_reference');
            $table->unsignedBigInteger('imported_minor');
            $table->unsignedBigInteger('expected_minor')->nullable();
            $table->string('imported_status', 12)->nullable();
            $table->string('expected_status', 12)->nullable();
            $table->string('result', 16);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_note', 300)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unique(['batch_id', 'order_reference'], 'remit_recon_batch_order');
            $table->index(['order_reference', 'batch_id'], 'remit_recon_order_batch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remittance_reconciliation_lines');
        Schema::dropIfExists('remittance_reconciliation_batches');
    }
};
