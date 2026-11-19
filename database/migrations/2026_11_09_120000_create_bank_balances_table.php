<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 通帳の残高を、口座×日付で1件ずつ持つ（#66）。マイナスもある（当座借越）。分析用の書き出しには入れない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_balances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->date('balance_date');
            $table->bigInteger('balance_yen');
            $table->string('source', 20);
            $table->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['bank_account_id', 'balance_date']);
            $table->index(['company_id', 'balance_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_balances');
    }
};
