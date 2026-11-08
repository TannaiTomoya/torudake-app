<?php

use App\Enums\AiTask;
use App\Enums\CorrectionReason;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\SourceRecord;
use App\Models\User;
use App\Services\AnalysisExportSanitizer;
use App\Services\ConfirmEntryInput;
use App\Services\ConfirmEntryService;
use App\Services\TrainingDataExporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

// #31：分析用の書き出しは「許可した項目だけ」。架空の個人情報を全階層に入れても出ない

const EXPORT_SECRETS = [
    '山田太郎',
    'yamada.taro@example.invalid',
    '090-1234-5678',
    '岩手県八幡平市松尾第99地割',
    '普通口座 7654321',
    '秘密のメモ文',
    '例外メッセージの中の宛名',
];

beforeEach(function (): void {
    Storage::fake('evidence');
    Storage::fake('local');
});

/**
 * @return array{company: Company, owner: User, source: SourceRecord, entry: JournalEntry}
 */
function exportCompany(?Company $company = null, int $width = 400, bool $changeCategory = false): array
{
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner, company: $company);
    $source = sendReceipt($company, $owner, $width);
    /** @var JournalEntry $draft */
    $draft = $source->journalEntry()->firstOrFail();
    $categoryId = (string) $draft->category_id;
    if ($changeCategory) {
        $direction = $company->categories()->whereKey($categoryId)->value('direction');
        $categoryId = (string) $company->categories()->where('direction', $direction)->whereKeyNot($categoryId)->value('id');
    }

    $entry = app(ConfirmEntryService::class)->confirm($company, $owner, $source->id, new ConfirmEntryInput(
        entryDate: $draft->entry_date->toDateString(),
        vendor: $draft->vendor,
        description: $draft->description,
        categoryId: $categoryId,
        bankAccountId: (string) $draft->bank_account_id,
        amountYen: $draft->amountYen(),
        reason: $changeCategory ? CorrectionReason::WrongCategory : null,
        readingConfirmed: ['date'],
    ));

    return ['company' => $company, 'owner' => $owner, 'source' => $source, 'entry' => $entry];
}

/**
 * @return array{files: array<string, string>, rows: array<string, list<array<string, mixed>>>, manifest: array<string, mixed>}
 */
function readExport(Company $company, ?CarbonImmutable $month = null): array
{
    $export = app(TrainingDataExporter::class)->export($company, $month ?? CarbonImmutable::now()->startOfMonth());
    $path = tempnam(sys_get_temp_dir(), 'safety-zip-');
    file_put_contents($path, $export['contents']);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    $files = [];
    $rows = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $files[$name] = (string) $zip->getFromIndex($i);
    }
    $zip->close();
    unlink($path);

    foreach (['ai_runs.jsonl', 'ai_corrections.jsonl', 'journal_entries.jsonl'] as $name) {
        $rows[$name] = array_map(
            fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", $files[$name]))),
        );
    }

    return ['files' => $files, 'rows' => $rows, 'manifest' => json_decode($files['manifest.json'], true, flags: JSON_THROW_ON_ERROR)];
}

/**
 * 出力の文字列は、仮名のキー・日付・UTC時刻・記号だけの短い語（種類・版・モデル名）のどれかでなければならない。
 *
 * @return list<string>
 */
function disallowedLeaves(mixed $value, string $path = ''): array
{
    if (is_array($value)) {
        $bad = [];
        foreach ($value as $key => $child) {
            $bad = [...$bad, ...disallowedLeaves($child, $path.'.'.$key)];
        }

        return $bad;
    }

    if (! is_string($value)) {
        return [];
    }

    $ok = preg_match('/^[a-z]_[0-9a-f]{16}$/', $value) === 1
        || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
        || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) === 1
        || preg_match('/^[A-Za-z0-9._:\-]{1,80}$/', $value) === 1;

    return $ok ? [] : ["{$path}={$value}"];
}

/**
 * 保存済みの全階層に、架空の個人情報と自由文を埋める。
 */
function poisonStoredText(Company $company): void
{
    [$name, $email, $phone, $address, $account, $memo, $message] = EXPORT_SECRETS;

    foreach ($company->aiRuns()->get() as $run) {
        $run->forceFill([
            'input' => [
                'note' => $memo,
                'company_name' => $name,
                'full_text' => "{$name} {$phone}",
                'addressee' => $name,
                'nested' => ['deep' => ['email' => $email, 'list' => [$address, $account]]],
                'lines' => [['side' => 'debit', 'account' => $name, 'amount_yen' => 1000, 'memo' => $memo]],
                'recent_entries' => [['vendor' => $name, 'amount_yen' => 1000]],
                'corrections' => [['vendor' => $name, 'before' => $email, 'after' => $address, 'kernel' => $memo]],
            ],
            'output' => [
                'full_text' => "{$name} {$email} {$phone} {$address} {$account}",
                'date' => $address,
                'vendor' => $name,
                'addressee' => $name,
                'addressee_kind' => $name,
                'total_yen' => $phone,
                'payment_method' => $account,
                'registration_number' => 'T1234567890123',
                'reason' => $memo,
                'description' => $memo,
                'category' => $name,
                'bank_account' => $account,
                'concerns' => [$name, $address],
                'tax_breakdown' => [['rate' => $name, 'amount_yen' => $phone, 'memo' => $email]],
                'confidence' => ['date' => $memo, 'vendor' => 0.5, 'total' => 'x'],
                'summary' => $memo,
                'unknown' => ['deep' => [$name]],
            ],
            'error' => $message,
        ])->save();
    }

    foreach ($company->aiCorrections()->get() as $correction) {
        $correction->forceFill([
            'before' => $name,
            'after' => $email,
            'kernel' => "vendor:{$name} → category:{$address}",
            'vendor' => $name,
        ])->save();
    }

    foreach ($company->journalEntries()->get() as $entry) {
        $entry->forceFill(['vendor' => $name, 'description' => "{$memo} {$phone}"])->save();
    }

    foreach ($company->categories()->get() as $category) {
        $category->forceFill(['name' => "{$name}分類{$category->sort_order}"])->save();
    }
    foreach ($company->bankAccounts()->get() as $bankAccount) {
        $bankAccount->forceFill(['name' => "{$account}口座{$bankAccount->id}"])->save();
    }
    foreach ($company->accounts()->get() as $accountRow) {
        $accountRow->forceFill(['name' => "{$address}科目{$accountRow->code}"])->save();
    }
}

test('架空の人名・メール・電話・住所・口座・自由文を全階層に入れても、どのファイルにも出ない', function (): void {
    ['company' => $company] = exportCompany(changeCategory: true);
    poisonStoredText($company);

    $export = readExport($company);

    foreach ($export['files'] as $name => $text) {
        foreach (EXPORT_SECRETS as $secret) {
            expect($text)->not->toContain($secret, "{$name} に「{$secret}」が出ています");
        }
        expect($text)->not->toContain('T1234567890123')->not->toContain('分類')->not->toContain('口座')->not->toContain('科目');
    }

    expect($export['rows']['ai_runs.jsonl'])->not->toBeEmpty()
        ->and($export['rows']['ai_corrections.jsonl'])->not->toBeEmpty()
        ->and($export['rows']['journal_entries.jsonl'])->not->toBeEmpty();

    // 文字列の値は、仮名のキー・日付・時刻・記号の短い語だけ
    expect(disallowedLeaves($export['rows']))->toBe([]);
});

test('各ファイルが持つ項目は許可した名前だけ。raw の input・output・error・description・kernel の文は無い', function (): void {
    ['company' => $company] = exportCompany(changeCategory: true);

    $export = readExport($company);

    foreach ($export['rows']['ai_runs.jsonl'] as $row) {
        expect(array_keys($row))->toBe([
            'id', 'task', 'subject_kind', 'subject_key', 'model', 'prompt_version', 'driver', 'valid', 'has_error',
            'input_tokens', 'output_tokens', 'latency_ms', 'created_at', 'input_features', 'output_features',
        ]);
    }
    expect(array_keys($export['rows']['ai_corrections.jsonl'][0]))->toBe([
        'id', 'ai_run_id', 'journal_entry_id', 'field', 'before', 'after', 'reason', 'kernel_kind', 'vendor_key', 'corrected_by', 'created_at',
    ])->and(array_keys($export['rows']['journal_entries.jsonl'][0]))->toBe([
        'id', 'entry_date', 'status', 'vendor_key', 'category_key', 'payment_key', 'amount_yen', 'lines', 'ai_run_id', 'origin',
        'concern_flags', 'confirmed_by', 'confirmed_at',
    ])->and(array_keys($export['rows']['journal_entries.jsonl'][0]['lines'][0]))->toBe(['side', 'account_code', 'amount_yen']);
});

test('読み取りの出力は型を確かめた値だけ。型が違う値は null になり、文字は通らない', function (): void {
    ['company' => $company] = exportCompany();
    poisonStoredText($company);

    $export = readExport($company);
    $read = collect($export['rows']['ai_runs.jsonl'])->firstWhere('task', AiTask::ReadReceipt->value);

    expect($read['output_features'])->toMatchArray([
        'date' => null,
        'total_yen' => null,
        'addressee_kind' => null,
        'payment_method' => null,
        'has_registration_number' => true,
    ])->and($read['output_features']['confidence'])->toBe(['date' => null, 'vendor' => 0.5, 'total' => null])
        ->and($read['output_features']['tax_breakdown'])->toBe([['rate' => null, 'amount_yen' => null]])
        ->and($read['input_features'])->toBe(['has_note' => true])
        ->and($read['has_error'])->toBeTrue();
});

test('L3 の評価用：検算の実行は、同じ実行の行（side・金額）と型を確かめた合計・一致だけを出す', function (): void {
    ['company' => $company, 'entry' => $entry] = exportCompany();
    $sanitizer = new AnalysisExportSanitizer($company);

    $export = readExport($company);
    $check = collect($export['rows']['ai_runs.jsonl'])->firstWhere('task', AiTask::CheckEntry->value);

    expect($check)->not->toBeNull()
        ->and($check['subject_kind'])->toBe('JournalEntry')
        ->and($check['subject_key'])->toBe($sanitizer->key('e', $entry->id))
        ->and(array_keys($check['input_features']))->toBe(['entry_date', 'lines', 'recent_entries_count'])
        ->and(array_keys($check['output_features']))->toBe(['debit_total', 'credit_total', 'balanced', 'duplicate_suspected', 'private_suspected', 'concerns_count']);

    $debit = array_sum(array_map(fn (array $line): int => $line['side'] === 'debit' ? $line['amount_yen'] : 0, $check['input_features']['lines']));
    $credit = array_sum(array_map(fn (array $line): int => $line['side'] === 'credit' ? $line['amount_yen'] : 0, $check['input_features']['lines']));

    // 仮名のキーで結んで、AI の合計と、確定した仕訳の金額を比べられる
    $confirmed = collect($export['rows']['journal_entries.jsonl'])->firstWhere('id', $check['subject_key']);
    expect($debit)->toBe($check['output_features']['debit_total'])
        ->and($credit)->toBe($check['output_features']['credit_total'])
        ->and($confirmed['amount_yen'])->toBe($debit);
});

test('会社をまたいで結べない：同じ店名・同じ人でも、会社ごとにキーが全部違う', function (): void {
    ['company' => $a, 'owner' => $owner] = exportCompany(changeCategory: true);
    ['company' => $b] = exportCompany(changeCategory: true);

    $keysOf = function (Company $company): array {
        $export = readExport($company);
        $keys = [];
        array_walk_recursive($export['rows'], function (mixed $value) use (&$keys): void {
            if (is_string($value) && preg_match('/^[a-z]_[0-9a-f]{16}$/', $value) === 1) {
                $keys[$value] = true;
            }
        });

        return array_keys($keys);
    };

    $keysA = $keysOf($a);
    $keysB = $keysOf($b);

    expect($keysA)->not->toBeEmpty()->and($keysB)->not->toBeEmpty()
        ->and(array_intersect($keysA, $keysB))->toBe([])
        ->and((new AnalysisExportSanitizer($a))->key('u', $owner->id))->not->toBe((new AnalysisExportSanitizer($b))->key('u', $owner->id))
        ->and((new AnalysisExportSanitizer($a))->vendorKey('〇〇石油 八幡平店'))->not->toBe((new AnalysisExportSanitizer($b))->vendorKey('〇〇石油 八幡平店'))
        ->and((new AnalysisExportSanitizer($a))->companyKey())->not->toBe((new AnalysisExportSanitizer($b))->companyKey());
});

test('同じ会社の中では、店・分類・仕訳・AI実行のキーがファイルをまたいで一致し、集計できる', function (): void {
    ['company' => $company, 'entry' => $entry] = exportCompany(changeCategory: true);
    $sanitizer = new AnalysisExportSanitizer($company);

    $export = readExport($company);
    $runs = collect($export['rows']['ai_runs.jsonl']);
    $corrections = collect($export['rows']['ai_corrections.jsonl']);
    $entries = collect($export['rows']['journal_entries.jsonl']);

    $confirmed = $entries->sole();
    $read = $runs->firstWhere('task', AiTask::ReadReceipt->value);
    $journalize = $runs->firstWhere('task', AiTask::Journalize->value);
    $correction = $corrections->sole();

    expect($confirmed['id'])->toBe($sanitizer->key('e', $entry->id))
        ->and($confirmed['origin'])->toBe('corrected')
        ->and($confirmed['ai_run_id'])->toBe($journalize['id'])
        ->and($correction['journal_entry_id'])->toBe($confirmed['id'])
        ->and($correction['ai_run_id'])->toBe($journalize['id'])
        ->and($correction['field'])->toBe('category')
        ->and($correction['kernel_kind'])->toBe('vendor_to_category')
        ->and($correction['after'])->toBe($confirmed['category_key'])
        ->and($correction['before'])->toBe($journalize['output_features']['category_key'])
        ->and($correction['vendor_key'])->toBe($confirmed['vendor_key'])
        ->and($read['output_features']['vendor_key'])->toBe($confirmed['vendor_key'])
        ->and($confirmed['category_key'])->toBe($sanitizer->key('c', $entry->category_id));

    // 例：直された回数を店のキーごと・欄ごとに数える
    expect($corrections->groupBy('vendor_key')->map->count()->all())->toBe([$confirmed['vendor_key'] => 1])
        ->and($corrections->countBy('kernel_kind')->all())->toBe(['vendor_to_category' => 1]);

    // 2回書き出しても同じキー（月をまたいで同じ店を追える）
    expect(readExport($company)['rows']['journal_entries.jsonl'][0]['vendor_key'])->toBe($confirmed['vendor_key']);
});

test('manifest：版・期間・時刻の基準・件数・ハッシュが3つのJSONLと一致する', function (): void {
    ['company' => $company] = exportCompany(changeCategory: true);

    $export = readExport($company);
    $manifest = $export['manifest'];
    $month = CarbonImmutable::now()->startOfMonth();

    expect($manifest['schema_version'])->toBe(AnalysisExportSanitizer::SCHEMA_VERSION)
        ->and($manifest['metric_version'])->toBe(AnalysisExportSanitizer::METRIC_VERSION)
        ->and($manifest['company_key'])->toBe((new AnalysisExportSanitizer($company))->companyKey())
        ->and($manifest['month'])->toBe($month->format('Y-m'))
        ->and($manifest['period']['timezone'])->toBe(config('app.timezone'))
        ->and($manifest['period']['from_utc'])->toBe($month->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and($manifest['period']['to_utc'])->toBe($month->addMonth()->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and($manifest['sensitivity'])->toContain('匿名化ではない')
        ->and(array_keys($manifest['files']))->toBe(['ai_runs.jsonl', 'ai_corrections.jsonl', 'journal_entries.jsonl']);

    foreach ($manifest['files'] as $name => $info) {
        expect($info['rows'])->toBe(count($export['rows'][$name]))
            ->and($info['sha256'])->toBe(hash('sha256', $export['files'][$name]));
    }

    // 期間内の行だけ（時刻はUTC）
    foreach ([...$export['rows']['ai_runs.jsonl'], ...$export['rows']['ai_corrections.jsonl']] as $row) {
        expect($row['created_at'] >= $manifest['period']['from_utc'] && $row['created_at'] < $manifest['period']['to_utc'])->toBeTrue();
    }
});

test('期間をまたぐ関連：相手が別の月にある件数を manifest に出し、別の月の行は混ぜない', function (): void {
    ['company' => $company] = exportCompany(changeCategory: true);

    $run = $company->aiRuns()->where('task', AiTask::Journalize)->sole();
    $run->forceFill(['created_at' => now()->subMonthNoOverflow()->startOfMonth()->addDay()])->save();

    $export = readExport($company);

    expect(collect($export['rows']['ai_runs.jsonl'])->pluck('task')->all())->not->toContain(AiTask::Journalize->value)
        ->and($export['manifest']['links_outside_period']['ai_corrections.ai_run_id'])->toBe(1)
        ->and($export['manifest']['links_outside_period']['journal_entries.ai_run_id'])->toBe(1)
        ->and($export['manifest']['links_outside_period']['ai_corrections.journal_entry_id'])->toBe(0);

    // 前の月の方に書き出すと、その実行が入る
    $previous = readExport($company, CarbonImmutable::now()->startOfMonth()->subMonthNoOverflow());
    expect(collect($previous['rows']['ai_runs.jsonl'])->pluck('task')->all())->toBe([AiTask::Journalize->value]);
});

test('他社の AI実行・修正・仕訳は、どのファイルにも混ざらない', function (): void {
    ['company' => $company] = exportCompany(changeCategory: true);
    ['company' => $other, 'entry' => $otherEntry] = exportCompany(changeCategory: true);
    $otherKey = (new AnalysisExportSanitizer($other))->key('e', $otherEntry->id);

    $export = readExport($company);

    foreach ($export['files'] as $text) {
        expect($text)->not->toContain((string) $otherKey)->not->toContain($otherEntry->id);
    }
    expect($export['rows']['journal_entries.jsonl'])->toHaveCount(1)
        ->and($export['rows']['ai_corrections.jsonl'])->toHaveCount(1);
});

test('権限を失った後のダウンロードは403、未認証はログインへ、他社IDは404。成功時は no-store で、監査に版が残る', function (): void {
    ['company' => $company, 'owner' => $owner] = exportCompany(changeCategory: true);
    $membership = $company->memberships()->where('user_id', $owner->id)->firstOrFail();
    ['user' => $outsider] = createCompanyWithMember(Role::Owner);
    $url = route('company.exports.training', ['company' => $company, 'month' => now()->format('Y-m')]);

    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs($outsider)->get($url)->assertNotFound();

    $this->actingAs($owner)->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/zip')
        ->assertHeader('Cache-Control', 'no-store, private');

    $log = AuditLog::where('action', 'training.export')->latest('created_at')->firstOrFail();
    expect($log->after)->toMatchArray(['schema_version' => AnalysisExportSanitizer::SCHEMA_VERSION, 'month' => now()->format('Y-m')]);

    // 開いたまま経理に変わった人は、次のダウンロードから拒否される
    $membership->forceFill(['role' => Role::Editor])->save();
    $this->actingAs($owner->fresh())->get($url)->assertForbidden();
    expect(AuditLog::where('action', 'training.export')->count())->toBe(1);
});

test('会社名を変えても、仮名のキーは変わらない（名前の変更で結合が壊れない）', function (): void {
    ['company' => $company, 'entry' => $entry] = exportCompany();
    $before = readExport($company)['rows']['journal_entries.jsonl'][0];

    $company->forceFill(['name' => '名前を変えた建機'])->save();
    $after = readExport($company)['rows']['journal_entries.jsonl'][0];

    expect($after['id'])->toBe($before['id'])
        ->and($after['vendor_key'])->toBe($before['vendor_key'])
        ->and($after['id'])->toBe((new AnalysisExportSanitizer($company))->key('e', $entry->id))
        ->and($after['id'])->not->toBe($entry->id);
});
