<?php

use App\Support\VendorKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 履歴からの推薦（#54）。
     * - journal_entries.vendor_key：正規化した店名。同じ店の履歴を、件数の上限なしに索引で引くため。
     * - journal_entries.recommendation：下書きを作った時点の推薦の記録（元の AI の案・履歴の候補・根拠）。
     * - first_confirmations.recommendation：初回確定のとき、候補をどう扱ったか（採用・別を選んだ・保留・失効）。
     */
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->string('vendor_key', 100)->nullable()->after('vendor');
            $table->json('recommendation')->nullable()->after('draft_payment_origin');
            $table->index(['company_id', 'vendor_key', 'status'], 'journal_entries_vendor_key_index');
        });

        Schema::table('first_confirmations', function (Blueprint $table): void {
            $table->json('recommendation')->nullable()->after('duplicate');
        });

        DB::table('journal_entries')->whereNotNull('vendor')->orderBy('id')->chunk(200, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('journal_entries')->where('id', $row->id)->update(['vendor_key' => VendorKey::of($row->vendor)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('first_confirmations', function (Blueprint $table): void {
            $table->dropColumn('recommendation');
        });

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropIndex('journal_entries_vendor_key_index');
            $table->dropColumn(['vendor_key', 'recommendation']);
        });
    }
};
