<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remit_agreement_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16)->index();
            $table->mediumText('body');
            $table->unsignedBigInteger('published_by');
            $table->timestamp('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remit_agreement_versions');
    }
};
