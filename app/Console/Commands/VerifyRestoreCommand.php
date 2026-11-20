<?php

namespace App\Console\Commands;

use App\Enums\EntryStatus;
use App\Models\Company;
use App\Models\User;
use App\Services\BackupManifest;
use App\Services\WorkbookService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use JsonException;
use Throwable;

/**
 * 復元した環境が、バックアップを取ったときと同じかを確かめる（#35）。
 * 1) 件数と貸借の合計が控えと同じ　2) アプリの鍵が合っている（2段階認証の秘密を読める）　3) Excel を DB から作り直せる
 * 名前や金額の明細は表示しない。--rebuild-workbooks を付けると、今ある Excel を捨てて DB から作り直す（復元の直後に使う）。
 */
class VerifyRestoreCommand extends Command
{
    protected $signature = 'torudake:verify-restore {manifest : バックアップに入っていた控え（manifest.json）の場所} {--skip-workbooks : Excel を確かめない} {--rebuild-workbooks : 今ある Excel を捨てて、DB から作り直して確かめる}';

    protected $description = '復元した DB を、バックアップの控え・アプリの鍵・Excel の作り直しで確かめる';

    public function handle(BackupManifest $manifest, WorkbookService $workbooks): int
    {
        $expected = $this->readManifest((string) $this->argument('manifest'));

        if ($expected === null) {
            return self::FAILURE;
        }

        $problems = [
            ...$this->checkManifest($manifest, $expected),
            ...$this->checkAppKey(),
            ...($this->option('skip-workbooks') ? [] : $this->checkWorkbooks($workbooks, (bool) $this->option('rebuild-workbooks'))),
        ];

        if ($problems !== []) {
            $this->error('復元の確認：問題が '.count($problems).' 件あります。');

            return self::FAILURE;
        }

        $this->info('復元の確認：すべて合っています。');

        return self::SUCCESS;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function readManifest(string $path): ?array
    {
        if (! is_file($path)) {
            $this->error("控えが見つかりません：{$path}");

            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('控えを JSON として読めません。');

            return null;
        }

        if (! is_array($decoded) || ($decoded['version'] ?? null) !== BackupManifest::VERSION) {
            $this->error('控えの版が、このアプリの版と合いません。');

            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<array-key, mixed>  $expected
     * @return list<string>
     */
    private function checkManifest(BackupManifest $manifest, array $expected): array
    {
        $differences = $manifest->differences($expected, $manifest->build());

        if ($differences === []) {
            $this->line('OK 件数と貸借の合計：控えと同じ');

            return [];
        }

        $this->line('NG 件数か貸借の合計が、控えと違う');
        foreach ($differences as $difference) {
            $this->line("   {$difference}");
        }

        return $differences;
    }

    /**
     * @return list<string>
     */
    private function checkAppKey(): array
    {
        /** @var User|null $user */
        $user = User::query()->whereNotNull('two_factor_secret')->orderBy('id')->first();

        if ($user === null) {
            $this->line('-- アプリの鍵：2段階認証を設定した人がいないので、確かめられない');

            return [];
        }

        try {
            decrypt((string) $user->two_factor_secret);
        } catch (DecryptException) {
            $this->line('NG アプリの鍵（APP_KEY）が、バックアップを取ったときと違う。2段階認証でログインできない');

            return ['app_key'];
        }

        $this->line('OK アプリの鍵：2段階認証の秘密を読める');

        return [];
    }

    /**
     * @return list<string>
     */
    private function checkWorkbooks(WorkbookService $workbooks, bool $rebuild): array
    {
        $problems = [];
        $checked = 0;

        foreach (Company::query()->orderBy('id')->cursor() as $company) {
            $expectedRows = $company->journalEntries()
                ->whereIn('status', [EntryStatus::Confirmed, EntryStatus::Returned])
                ->count();

            try {
                $contents = $rebuild ? $workbooks->rebuild($company) : $workbooks->contents($company);
                $rows = $workbooks->ensureWorkbook($company)->next_row - 2;
            } catch (Throwable $e) {
                $problems[] = "workbook:{$company->id}";
                $this->line("NG Excel を読めない・作り直せない（会社 {$company->id}）：".$e::class);

                continue;
            }

            if (! str_starts_with($contents, 'PK')) {
                $problems[] = "workbook:{$company->id}";
                $this->line("NG Excel のファイルが壊れている（会社 {$company->id}）。--rebuild-workbooks で DB から作り直せる");

                continue;
            }

            if ($rows !== $expectedRows) {
                $problems[] = "workbook:{$company->id}";
                $this->line("NG Excel の行数が合わない（会社 {$company->id}）：確定 {$expectedRows} 件 → Excel {$rows} 行");

                continue;
            }

            $checked++;
        }

        if ($problems === []) {
            $this->line(($rebuild ? 'OK Excel の作り直し' : 'OK Excel')."：{$checked} 社とも、確定した仕訳の件数と同じ行数");
        }

        return $problems;
    }
}
