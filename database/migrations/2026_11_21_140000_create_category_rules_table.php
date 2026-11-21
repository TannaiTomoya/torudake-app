<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 分類のルール（#69）。店名にキーワードを含む → 分類。
 * 並び順（position）の小さいほうが先。新規は一番上（position をずらして 0 に入れる）。
 * 分類は ID で持つ。キーワードの照合は VendorKey でそろえた文字列。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('keyword', 60);
            $table->foreignUuid('category_id')->constrained('categories')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'keyword']);
            $table->index(['company_id', 'position']);
        });

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->string('draft_category_origin', 20)->nullable()->after('draft_payment_origin');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropColumn('draft_category_origin');
        });
        Schema::dropIfExists('category_rules');
    }
};
