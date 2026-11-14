<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remittance_quote_records', function (Blueprint $table) {
            $table->timestamp('draft_saved_at')->nullable();
            $table->index(['member_id', 'draft_saved_at', 'id'], 'remittance_saved_drafts');
        });
    }

    public function down(): void
    {
        Schema::table('remittance_quote_records', function (Blueprint $table) {
            $table->dropIndex('remittance_saved_drafts');
            $table->dropColumn('draft_saved_at');
        });
    }
};
