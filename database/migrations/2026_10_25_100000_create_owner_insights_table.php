<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_insights', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->char('input_hash', 64)->nullable();
            $table->json('output')->nullable();
            $table->foreignUuid('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->dateTime('generated_at')->nullable();
            $table->char('pending_hash', 64)->nullable();
            $table->dateTime('pending_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_insights');
    }
};
