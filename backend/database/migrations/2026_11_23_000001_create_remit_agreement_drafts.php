<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remit_agreement_drafts', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16)->unique();
            $table->mediumText('body');
            $table->uuid('revision');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remit_agreement_drafts');
    }
};
