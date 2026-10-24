<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->unsignedBigInteger('concern_amount_yen')->default(30000)->after('is_demo');
            $table->unsignedBigInteger('approval_required_yen')->nullable()->after('concern_amount_yen');
        });

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->foreignUuid('bank_account_id')->nullable()->after('category_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->after('approved_at')->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at')->nullable()->after('confirmed_by');
            $table->foreignId('returned_by')->nullable()->after('confirmed_at')->constrained('users')->restrictOnDelete();
            $table->dateTime('returned_at')->nullable()->after('returned_by');
            $table->json('concern_flags')->nullable()->after('ai_check');
            $table->dateTime('owner_seen_at')->nullable()->after('concern_flags');
            $table->unsignedInteger('workbook_row')->nullable()->after('owner_seen_at');
        });

        Schema::table('source_records', function (Blueprint $table): void {
            $table->uuid('batch_id')->nullable()->after('evidence_id')->index();
        });

        $this->convertLegacyData();
    }

    /**
     * 版2.0 の値を版3.0 に写す（承認済み → 確定、仕訳済み → 確定、各社に「後日の請求」の口座）。何度呼んでも同じ結果になる。
     */
    public function convertLegacyData(): void
    {
        DB::table('journal_entries')->where('status', 'approved')->orderBy('id')->each(function (object $entry): void {
            DB::table('journal_entries')->where('id', $entry->id)->update([
                'status' => 'confirmed',
                'confirmed_by' => $entry->approved_by,
                'confirmed_at' => $entry->approved_at,
            ]);
        });

        DB::table('source_records')->where('status', 'journaled')->orderBy('id')->each(function (object $source): void {
            DB::table('source_records')->where('id', $source->id)->update(['status' => 'confirmed']);
        });

        DB::table('companies')->orderBy('id')->each(function (object $company): void {
            $payableId = DB::table('accounts')->where('company_id', $company->id)->where('code', '202')->value('id');
            $exists = DB::table('bank_accounts')->where('company_id', $company->id)->where('kind', 'invoice')->exists();

            if ($payableId === null || $exists) {
                return;
            }

            DB::table('bank_accounts')->insert([
                'id' => (string) Str::uuid7(),
                'company_id' => $company->id,
                'name' => '後日の請求書（未払金）',
                'kind' => 'invoice',
                'account_id' => $payableId,
                'is_default_for_receipts' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('journal_entries')->whereIn('status', ['confirmed', 'returned'])->orderBy('id')->each(function (object $entry): void {
            DB::table('journal_entries')->where('id', $entry->id)->update([
                'status' => 'approved',
                'approved_by' => $entry->confirmed_by,
                'approved_at' => $entry->confirmed_at,
            ]);
        });

        DB::table('source_records')->where('status', 'confirmed')->orderBy('id')->each(function (object $source): void {
            DB::table('source_records')->where('id', $source->id)->update(['status' => 'journaled']);
        });

        Schema::table('source_records', function (Blueprint $table): void {
            $table->dropIndex(['batch_id']);
            $table->dropColumn('batch_id');
        });

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn(['confirmed_at', 'returned_at', 'concern_flags', 'owner_seen_at', 'workbook_row']);
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['concern_amount_yen', 'approval_required_yen']);
        });
    }
};
