<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 初回確定の時点の記録。1仕訳に1行で、書き換えない（再確定・戻しは entry_post_events に別に残す）
        Schema::create('first_confirmations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignUuid('source_record_id')->nullable()->constrained('source_records')->nullOnDelete();
            $table->string('kind', 10);
            $table->string('evaluation_class', 10);
            $table->foreignUuid('ai_run_id')->nullable()->constrained('ai_runs')->restrictOnDelete();
            $table->string('driver', 40)->nullable();
            $table->string('prompt_version', 80)->nullable();
            $table->boolean('required_complete');
            $table->json('changed_fields');
            $table->json('filled_fields');
            $table->boolean('description_changed');
            $table->json('draft_origin');
            $table->json('reading_methods');
            $table->json('duplicate');
            $table->string('reason', 20)->nullable();
            $table->foreignId('first_confirmed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('first_confirmed_at');
            $table->timestamps();

            $table->index(['company_id', 'first_confirmed_at']);
        });

        // 初回確定のあとの出来事（社長が戻した・経理が再確定した）。過去の記録は書き換えない
        Schema::create('entry_post_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->string('kind', 12);
            $table->json('changed_fields');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['company_id', 'journal_entry_id']);
        });

        // AI が下書きを作ったときの、支払い元の決め方（history=同じ店で人が直した履歴 / invoice_rule / ai / default）
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->string('draft_payment_origin', 20)->nullable()->after('reading_review');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropColumn('draft_payment_origin');
        });
        Schema::dropIfExists('entry_post_events');
        Schema::dropIfExists('first_confirmations');
    }
};
