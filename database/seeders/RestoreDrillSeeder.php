<?php

namespace Database\Seeders;

use App\Enums\EntryKind;
use App\Enums\EntryStatus;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\JournalLineWriter;
use Illuminate\Database\Seeder;

/**
 * 復元の練習（#35、docs/server/restore-drill.sh）で使う架空のデータ。
 * 開発用の初期データ（会社2つ・人4人）に、確定・下書き・戻された仕訳を足す。数字はすべて架空。
 */
class RestoreDrillSeeder extends Seeder
{
    /**
     * 会社名 => [日付, 分類, 金額, 状態] の並び
     *
     * @var array<string, list<array{string, string, int, EntryStatus}>>
     */
    private const ENTRIES = [
        '丹内建機（デモ）' => [
            ['2026-08-03', '燃料', 5200, EntryStatus::Confirmed],
            ['2026-08-10', '部品・修理', 38500, EntryStatus::Confirmed],
            ['2026-08-21', '売上', 220000, EntryStatus::Confirmed],
            ['2026-09-02', '消耗品', 1980, EntryStatus::Confirmed],
            ['2026-09-15', '通信', 7700, EntryStatus::Returned],
            ['2026-09-20', '雑費', 640, EntryStatus::Draft],
        ],
        'デモ工務店' => [
            ['2026-08-05', '売上', 130000, EntryStatus::Confirmed],
            ['2026-08-18', '手数料', 880, EntryStatus::Confirmed],
        ],
    ];

    public function run(JournalLineWriter $lines): void
    {
        if (app()->isProduction()) {
            return;
        }

        $this->call(DatabaseSeeder::class);

        foreach (self::ENTRIES as $companyName => $entries) {
            /** @var Company $company */
            $company = Company::query()->where('name', $companyName)->firstOrFail();
            $bankAccount = $company->bankAccounts()->where('name', '現金')->firstOrFail();

            foreach ($entries as [$date, $categoryName, $amount, $status]) {
                $category = $company->categories()->where('name', $categoryName)->firstOrFail();

                $entry = new JournalEntry;
                $entry->forceFill([
                    'company_id' => $company->id,
                    'entry_date' => $date,
                    'kind' => EntryKind::Normal,
                    'status' => $status,
                    'vendor' => '架空商店 練習店',
                    'category_id' => $category->id,
                    'bank_account_id' => $bankAccount->id,
                    'confirmed_at' => $status === EntryStatus::Draft ? null : now(),
                ])->save();

                $lines->rewrite($entry, $category, $bankAccount, $amount);
            }
        }
    }
}
