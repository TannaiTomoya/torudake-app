<?php

/**
 * 復元の練習（docs/server/restore-drill.sh）から呼ぶ。戻した DB で、実際の処理を通す（#35）。
 * ログイン（パスワードと2段階認証）→ 権限 → 送る → AI の下書き（偽物）→ 経理の保存 → Excel に1行。
 * 練習用の DB でしか動かない。写真と Excel は捨てる置き場（Storage::fake）に書き、最後に消す。
 */

use App\Enums\EntryStatus;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\ConfirmEntryInput;
use App\Services\ConfirmEntryService;
use App\Services\SubmitEvidence;
use App\Services\WorkbookService;
use Database\Factories\UserFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\TwoFactorAuthenticationProvider;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PragmaRX\Google2FA\Google2FA;

$database = DB::connection()->getDatabaseName();
if (app()->isProduction() || ! str_starts_with($database, 'torudake_drill_') || config('torudake.ai.driver') !== 'fake') {
    throw new RuntimeException("練習用の環境ではありません（DB：{$database}）。何もしません。");
}

// 写真と Excel は、捨てる置き場に書く（開発の置き場には何も残さない）
$disks = array_unique([WorkbookService::DISK, (string) config('torudake.evidence.disk')]);
foreach ($disks as $disk) {
    Storage::fake($disk);
}

$check = function (bool $ok, string $label): void {
    if (! $ok) {
        throw new RuntimeException("NG {$label}");
    }
    echo "OK {$label}".PHP_EOL;
};

try {
    $company = Company::query()->where('name', '丹内建機（デモ）')->firstOrFail();
    $otherCompany = Company::query()->where('name', 'デモ工務店')->firstOrFail();
    $editor = User::query()->where('email', 'editor@example.com')->firstOrFail();
    $submitter = User::query()->where('email', 'submitter@example.com')->firstOrFail();
    $otherOwner = User::query()->where('email', 'other@example.com')->firstOrFail();

    // ログイン：戻したパスワードと、戻した2段階認証の秘密（アプリの鍵で開ける）が使える
    $secret = decrypt((string) $editor->two_factor_secret);
    $check(
        Hash::check(UserFactory::DEV_PASSWORD, (string) $editor->password)
            && ! Hash::check('wrong-password', (string) $editor->password)
            && app(TwoFactorAuthenticationProvider::class)->verify($secret, app(Google2FA::class)->getCurrentOtp($secret)),
        'ログイン（パスワードと2段階認証のコード）',
    );

    // 権限：経理は自社の一覧を見られる。他社の人・送るだけの人は見られない
    $check(
        Gate::forUser($editor)->allows('viewSources', $company)
            && ! Gate::forUser($editor)->allows('viewSources', $otherCompany)
            && ! Gate::forUser($otherOwner)->allows('viewSources', $company)
            && ! Gate::forUser($submitter)->allows('viewSources', $company)
            && Gate::forUser($submitter)->allows('submit', $company),
        '権限（自社だけ・送るだけの人は一覧を見られない）',
    );

    // 送る → AI の下書き（偽物）→ 経理の保存 → Excel に1行
    $workbooks = app(WorkbookService::class);
    $workbooks->contents($company);
    $rowsBefore = $workbooks->ensureWorkbook($company)->next_row - 2;

    $source = app(SubmitEvidence::class)->submit($company, $submitter, UploadedFile::fake()->image('receipt.jpg', 400, 600))->source?->fresh();
    /** @var JournalEntry|null $draft */
    $draft = $source?->journalEntry()->first();
    $check($source !== null && $draft !== null && $draft->status === EntryStatus::Draft, '送ると AI の下書きができる（確定はしない）');

    $entry = app(ConfirmEntryService::class)->confirm($company, $editor, $source->id, new ConfirmEntryInput(
        entryDate: $draft->entry_date->toDateString(),
        vendor: $draft->vendor,
        description: $draft->description,
        categoryId: (string) $draft->category_id,
        bankAccountId: (string) $draft->bank_account_id,
        amountYen: $draft->amountYen(),
        readingConfirmed: ['date'],
    ))->fresh();
    $check($entry?->status === EntryStatus::Confirmed, '経理の保存で確定');

    $path = tempnam(sys_get_temp_dir(), 'walk');
    file_put_contents($path, $workbooks->contents($company));
    $sheet = IOFactory::createReader('Xlsx')->load($path)->getSheetByName(WorkbookService::JOURNAL_SHEET);
    @unlink($path);
    $entry = $entry->fresh();
    $rowsAfter = $workbooks->ensureWorkbook($company)->next_row - 2;
    $check(
        $rowsAfter === $rowsBefore + 1
            && $entry?->workbook_row !== null
            && (string) $sheet?->getCell([1, $entry->workbook_row])->getValue() === WorkbookService::numberFor($entry),
        "Excel に1行入る（{$rowsBefore} 行から {$rowsAfter} 行）",
    );

    echo 'WALK-OK'.PHP_EOL;
} finally {
    foreach ($disks as $disk) {
        File::deleteDirectory(storage_path('framework/testing/disks/'.$disk));
    }
}
