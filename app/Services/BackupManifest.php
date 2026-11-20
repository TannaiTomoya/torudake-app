<?php

namespace App\Services;

use App\Enums\EntryStatus;
use App\Enums\Side;
use Illuminate\Support\Facades\DB;

/**
 * バックアップの「控え」（#35）。件数と貸借の合計だけを持つ。
 * バックアップを取る直前に作って一緒に保管し、復元したあとにもう一度作って比べる。
 * 名前・メール・金額の明細・摘要などは入れない（数だけ）。
 *
 * @phpstan-type Manifest array{
 *     version: int,
 *     tables: array<string, int>,
 *     memberships_by_role: array<string, int>,
 *     journal_entries_by_status: array<string, int>,
 *     confirmed_debit_yen: int,
 *     confirmed_credit_yen: int,
 *     migrations: int,
 *     last_migration: string|null
 * }
 */
class BackupManifest
{
    public const VERSION = 1;

    /**
     * 数える業務の表。cache・jobs・sessions・password_reset_tokens など、動き続ける表は入れない。
     * workbooks（Excel の台帳）も入れない。Excel は DB から作り直すもので、復元の確認が作り直すと行が増えるため。
     *
     * @var list<string>
     */
    public const TABLES = [
        'users', 'companies', 'memberships', 'accounts', 'categories', 'bank_accounts', 'bank_balances',
        'source_records', 'evidences', 'journal_entries', 'journal_lines',
        'ai_runs', 'ai_corrections', 'audit_logs', 'reading_confirmations', 'duplicate_reviews',
        'first_confirmations', 'entry_post_events', 'report_imports', 'external_monthly_figures',
        'external_customer_sales', 'owner_insights', 'passkeys',
    ];

    /**
     * @return Manifest
     */
    public function build(): array
    {
        $tables = [];
        foreach (self::TABLES as $table) {
            $tables[$table] = DB::table($table)->count();
        }

        return [
            'version' => self::VERSION,
            'tables' => $tables,
            'memberships_by_role' => $this->countBy('memberships', 'role'),
            'journal_entries_by_status' => $this->countBy('journal_entries', 'status'),
            'confirmed_debit_yen' => $this->confirmedTotal(Side::Debit),
            'confirmed_credit_yen' => $this->confirmedTotal(Side::Credit),
            'migrations' => DB::table('migrations')->count(),
            'last_migration' => $this->lastMigration(),
        ];
    }

    /**
     * 2つの控えの違い。同じなら空。
     *
     * @param  array<array-key, mixed>  $expected  バックアップに入っていた控え
     * @param  array<array-key, mixed>  $actual  復元したあとに作った控え
     * @return list<string>
     */
    public function differences(array $expected, array $actual): array
    {
        $expectedFlat = $this->flatten($expected);
        $actualFlat = $this->flatten($actual);
        $differences = [];

        $keys = array_unique([...array_keys($expectedFlat), ...array_keys($actualFlat)]);
        sort($keys);

        foreach ($keys as $key) {
            $before = $expectedFlat[$key] ?? null;
            $after = $actualFlat[$key] ?? null;

            if ($before !== $after) {
                $differences[] = sprintf('%s：バックアップ %s → 復元後 %s', $key, $this->show($before), $this->show($after));
            }
        }

        return $differences;
    }

    /**
     * @return array<string, int>
     */
    private function countBy(string $table, string $column): array
    {
        $counts = [];

        foreach (DB::table($table)->select($column)->selectRaw('count(*) as n')->groupBy($column)->orderBy($column)->get() as $row) {
            $counts[(string) $row->{$column}] = (int) $row->n;
        }

        return $counts;
    }

    private function confirmedTotal(Side $side): int
    {
        return (int) DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', EntryStatus::Confirmed->value)
            ->where('journal_lines.side', $side->value)
            ->sum('journal_lines.amount_yen');
    }

    private function lastMigration(): ?string
    {
        $name = DB::table('migrations')->orderByDesc('id')->value('migration');

        return is_string($name) ? $name : null;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, scalar|null>
     */
    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $name = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flat = [...$flat, ...$this->flatten($value, $name)];
            } elseif (is_scalar($value) || $value === null) {
                $flat[$name] = $value;
            }
        }

        return $flat;
    }

    private function show(mixed $value): string
    {
        return match (true) {
            $value === null => '（なし）',
            is_scalar($value) => (string) $value,
            default => '（読めない値）',
        };
    }
}
