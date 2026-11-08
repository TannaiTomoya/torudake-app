<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI の読み取りで、日付・店名・金額が「読めた」のか「読めず補った」のか（原文は ai_runs に残っているので複製しない）
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->json('reading_review')->nullable()->after('ai_check');
        });

        // 経理が保存したときに、確認が必要だった欄ごとに残す記録（値や読み取りの版が変われば新しい行になる）
        Schema::create('reading_confirmations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignUuid('ai_run_id')->nullable()->constrained('ai_runs')->restrictOnDelete();
            $table->string('field', 20);
            $table->string('method', 20);
            $table->json('reasons')->nullable();
            $table->string('value', 200)->nullable();
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->index(['company_id', 'journal_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_confirmations');

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropColumn('reading_review');
        });
    }
};
