<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 取引先と、入金・支払いの予定（#67）。
 * 取引先の本当の名前は business_partners.name の1か所だけに持つ（監査記録・分析用の書き出し・AI への入力には出さない）。
 * 個人のお客さんは名前を持たない（取引先なしの入金）。給料は全員の合計の1行だけで、取引先を付けない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_partners', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('name', 60);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('cash_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('direction', 10);
            $table->string('category', 20);
            $table->foreignUuid('business_partner_id')->nullable()->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount_yen');
            $table->date('due_date');
            $table->date('settled_on')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'settled_on', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_schedules');
        Schema::dropIfExists('business_partners');
    }
};
