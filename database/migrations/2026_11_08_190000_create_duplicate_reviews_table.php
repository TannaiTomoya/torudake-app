<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 二重計上の候補に対する経理の判断。判断した時点の対象・候補の値（fingerprint）も残し、値が変わったら古い判断は使わない
        Schema::create('duplicate_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('source_record_id')->constrained('source_records')->restrictOnDelete();
            $table->foreignUuid('target_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignUuid('candidate_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->string('decision', 20);
            $table->string('strength', 10);
            $table->json('reasons')->nullable();
            $table->string('reason', 200)->nullable();
            $table->char('target_fingerprint', 40);
            $table->char('candidate_fingerprint', 40);
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['company_id', 'source_record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_reviews');
    }
};
