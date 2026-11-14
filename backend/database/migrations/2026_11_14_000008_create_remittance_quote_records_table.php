<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remittance_rules', function (Blueprint $table) {
            $table->foreignId('revision_id')->nullable()->constrained('remittance_rule_revisions')->nullOnDelete();
        });
        Schema::create('remittance_quote_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('revision_id')->nullable()->constrained('remittance_rule_revisions')->nullOnDelete();
            $table->json('snapshot');
            $table->timestamps();
            $table->index(['member_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remittance_quote_records');
        Schema::table('remittance_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revision_id');
        });
    }
};
