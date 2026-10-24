<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('storage_key')->nullable();
            $table->char('sha256', 64);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->text('extracted_text')->nullable();
            $table->dateTime('purge_after');
            $table->dateTime('purged_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'sha256']);
            $table->index(['purge_after', 'purged_at']);
        });

        Schema::create('ai_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('task', 30);
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 36)->nullable();
            $table->string('model', 100);
            $table->string('prompt_version', 50);
            $table->string('driver', 20);
            $table->json('input');
            $table->json('output')->nullable();
            $table->boolean('valid')->default(false);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->date('entry_date');
            $table->string('kind', 20)->default('normal');
            $table->string('status', 20)->default('draft');
            $table->string('description', 200)->nullable();
            $table->string('vendor', 100)->nullable();
            $table->foreignUuid('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->string('returned_reason', 200)->nullable();
            $table->foreignUuid('ai_run_id')->nullable()->constrained('ai_runs')->restrictOnDelete();
            $table->json('ai_check')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'entry_date']);
        });

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->unsignedTinyInteger('line_no');
            $table->string('side', 10);
            $table->foreignUuid('account_id')->constrained('accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount_yen');
            $table->string('tax_code', 20)->nullable();
            $table->string('memo', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('source_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('kind', 20)->default('receipt');
            $table->string('status', 20)->default('new');
            $table->string('ai_status', 20)->default('waiting');
            $table->string('ai_stage', 20)->nullable();
            $table->string('ai_error', 200)->nullable();
            $table->foreignUuid('evidence_id')->unique()->constrained('evidences')->restrictOnDelete();
            $table->date('occurred_on')->nullable();
            $table->bigInteger('amount_yen')->nullable();
            $table->string('vendor', 100)->nullable();
            $table->string('note', 200)->nullable();
            $table->boolean('late')->default(false);
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('ignored_reason', 200)->nullable();
            $table->foreignId('ignored_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status', 'created_at']);
        });

        Schema::create('ai_corrections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('ai_run_id')->nullable()->constrained('ai_runs')->restrictOnDelete();
            $table->foreignUuid('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('field', 30);
            $table->text('before')->nullable();
            $table->text('after')->nullable();
            $table->string('reason', 30)->default('other');
            $table->string('kernel', 200)->nullable();
            $table->string('vendor', 100)->nullable();
            $table->foreignId('corrected_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_corrections');
        Schema::dropIfExists('source_records');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('evidences');
    }
};
