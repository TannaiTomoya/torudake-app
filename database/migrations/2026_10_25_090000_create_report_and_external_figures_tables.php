<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('kind', 32);
            $table->foreignUuid('evidence_id')->nullable()->constrained('evidences')->nullOnDelete();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->foreignUuid('ai_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16);
            $table->json('rows')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('saved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('saved_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });

        Schema::create('external_monthly_figures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->char('year_month', 7);
            $table->string('metric', 32);
            $table->bigInteger('amount_yen');
            $table->string('source', 16);
            $table->foreignUuid('report_import_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'year_month', 'metric']);
        });

        Schema::create('external_customer_sales', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->unsignedSmallInteger('rank');
            $table->string('label', 10);
            $table->bigInteger('amount_yen');
            $table->foreignUuid('report_import_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'period_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_customer_sales');
        Schema::dropIfExists('external_monthly_figures');
        Schema::dropIfExists('report_imports');
    }
};
