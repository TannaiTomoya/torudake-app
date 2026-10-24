<?php

namespace App\Services;

use App\Enums\ConcernFlag;
use App\Enums\EntryStatus;
use App\Enums\Side;
use App\Models\AiCorrection;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Workbook;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 会社に1冊の Excel（03 §4.6）。確定のたびに仕訳帳に1行足し、戻す・再確定は UUID の管理番号で同じ行を探して書き換える。
 */
class WorkbookService
{
    public const DISK = 'local';

    public const JOURNAL_SHEET = '仕訳帳';

    /** @var array<string, string> 列のキー => 見出し */
    public const DEFAULT_LAYOUT = [
        'number' => '管理番号',
        'date' => '日付',
        'vendor' => '取引先',
        'category' => '分類',
        'debit_account' => '借方科目',
        'debit_amount' => '借方金額',
        'credit_account' => '貸方科目',
        'credit_amount' => '貸方金額',
        'description' => '摘要',
        'status' => '状態',
        'confirmed_by' => '確定した人',
        'confirmed_at' => '確定した日時',
        'ai' => 'AIのまま／直した',
    ];

    /**
     * 管理番号には仕訳 ID 全体を使い、別の仕訳と重ならないようにする。
     */
    public static function numberFor(JournalEntry $entry): string
    {
        return strtoupper($entry->id);
    }

    /**
     * 確定・社長から戻しの仕訳を1行書く。何度呼んでも同じ行になる。
     */
    public function upsertRow(Company $company, string $entryId): void
    {
        $this->ensureWorkbook($company);

        DB::transaction(function () use ($company, $entryId): void {
            /** @var Workbook $workbook */
            $workbook = $company->workbook()->lockForUpdate()->firstOrFail();

            /** @var JournalEntry $entry */
            $entry = $company->journalEntries()->lockForUpdate()->findOrFail($entryId);

            if ($entry->status === EntryStatus::Draft) {
                return;
            }

            $spreadsheet = $this->load($workbook);
            $sheet = $this->journalSheet($spreadsheet, $workbook);

            $row = $this->findRow($sheet, $workbook, $company, $entry);
            if ($row === null) {
                $row = $workbook->next_row;
                $workbook->next_row = $row + 1;
            }

            $this->writeRow($sheet, $workbook, $row, $entry);
            $this->store($workbook, $spreadsheet);

            $workbook->forceFill(['version' => $workbook->version + 1])->save();

            if ($entry->workbook_row !== $row) {
                $entry->forceFill(['workbook_row' => $row])->save();
            }
        });
    }

    /**
     * 書けなかった確定の仕訳を足す（ダウンロードの前に呼ぶ）。
     */
    public function syncMissing(Company $company): void
    {
        $company->journalEntries()
            ->whereIn('status', [EntryStatus::Confirmed, EntryStatus::Returned])
            ->whereNull('workbook_row')
            ->orderBy('confirmed_at')
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (string $id) => $this->upsertRow($company, $id));
    }

    /**
     * ダウンロード用に、最新の1冊の中身を返す。
     */
    public function contents(Company $company): string
    {
        $workbook = $this->ensureWorkbook($company);
        $this->syncMissing($company);

        return (string) Storage::disk(self::DISK)->get($workbook->storage_key);
    }

    public function ensureWorkbook(Company $company): Workbook
    {
        $workbook = $company->workbook()->first();

        if ($workbook === null) {
            try {
                $workbook = new Workbook;
                $workbook->forceFill([
                    'company_id' => $company->id,
                    'storage_key' => "workbooks/{$company->id}.xlsx",
                    'layout' => array_keys(self::DEFAULT_LAYOUT),
                    'next_row' => 2,
                    'version' => 0,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                $workbook = $company->workbook()->firstOrFail();
            }
        }

        if (! Storage::disk(self::DISK)->exists($workbook->storage_key)) {
            DB::transaction(function () use ($company): void {
                /** @var Workbook $locked */
                $locked = $company->workbook()->lockForUpdate()->firstOrFail();
                if (Storage::disk(self::DISK)->exists($locked->storage_key)) {
                    return;
                }

                $spreadsheet = new Spreadsheet;
                $this->journalSheet($spreadsheet, $locked);
                $this->store($locked, $spreadsheet);
                $locked->forceFill(['next_row' => 2, 'version' => $locked->version + 1])->save();

                // ファイルを作り直したので、前の行番号は使えない。syncMissing で入れ直す。
                $company->journalEntries()->whereNotNull('workbook_row')->lockForUpdate()->get()
                    ->each(fn (JournalEntry $entry) => $entry->forceFill(['workbook_row' => null])->save());
            });
        }

        return $workbook->refresh();
    }

    private function load(Workbook $workbook): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'wb');
        file_put_contents($path, (string) Storage::disk(self::DISK)->get($workbook->storage_key));

        try {
            return IOFactory::createReader('Xlsx')->load($path);
        } finally {
            @unlink($path);
        }
    }

    private function store(Workbook $workbook, Spreadsheet $spreadsheet): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wb');

        try {
            (new Xlsx($spreadsheet))->save($path);
            Storage::disk(self::DISK)->put($workbook->storage_key, (string) file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    private function journalSheet(Spreadsheet $spreadsheet, Workbook $workbook): Worksheet
    {
        $sheet = $spreadsheet->getSheetByName(self::JOURNAL_SHEET);
        if ($sheet === null) {
            $sheet = $spreadsheet->getSheet(0);
            $sheet->setTitle(self::JOURNAL_SHEET);

            foreach ($workbook->layout as $index => $key) {
                $sheet->setCellValue([$index + 1, 1], self::DEFAULT_LAYOUT[$key] ?? $key);
            }

            $sheet->getStyle([1, 1, count($workbook->layout), 1])->getFont()->setBold(true);
            $sheet->freezePane('A2');
        }

        foreach ($workbook->layout as $index => $key) {
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth(match ($key) {
                'number' => 38,
                'vendor', 'description' => 24,
                'confirmed_at' => 18,
                'debit_amount', 'credit_amount', 'ai' => 14,
                default => 12,
            });
        }

        return $sheet;
    }

    private function findRow(Worksheet $sheet, Workbook $workbook, Company $company, JournalEntry $entry): ?int
    {
        $column = array_search('number', $workbook->layout, true);
        if ($column === false) {
            return null;
        }

        $number = self::numberFor($entry);
        $legacyNumber = strtoupper(substr(str_replace('-', '', $entry->id), -8));

        if ($entry->workbook_row !== null && (string) $sheet->getCell([$column + 1, $entry->workbook_row])->getValue() === $number) {
            return $entry->workbook_row;
        }

        if ($entry->workbook_row !== null
            && (string) $sheet->getCell([$column + 1, $entry->workbook_row])->getValue() === $legacyNumber
            && $this->legacyNumberIsUnique($company, $entry, $legacyNumber)) {
            return $entry->workbook_row;
        }

        for ($row = 2; $row < $workbook->next_row; $row++) {
            if ((string) $sheet->getCell([$column + 1, $row])->getValue() === $number) {
                return $row;
            }
        }

        if ($this->legacyNumberIsUnique($company, $entry, $legacyNumber)) {
            for ($row = 2; $row < $workbook->next_row; $row++) {
                if ((string) $sheet->getCell([$column + 1, $row])->getValue() === $legacyNumber) {
                    return $row;
                }
            }
        }

        return null;
    }

    private function legacyNumberIsUnique(Company $company, JournalEntry $entry, string $legacyNumber): bool
    {
        return ! $company->journalEntries()
            ->where('id', '<>', $entry->id)
            ->whereRaw("UPPER(SUBSTR(REPLACE(id, '-', ''), -8)) = ?", [$legacyNumber])
            ->exists();
    }

    private function writeRow(Worksheet $sheet, Workbook $workbook, int $row, JournalEntry $entry): void
    {
        $entry->load(['lines.account', 'category', 'confirmer']);

        /** @var JournalLine|null $debit */
        $debit = $entry->lines->first(fn (JournalLine $line) => $line->side === Side::Debit);
        /** @var JournalLine|null $credit */
        $credit = $entry->lines->first(fn (JournalLine $line) => $line->side === Side::Credit);

        $values = [
            'number' => self::numberFor($entry),
            'date' => Date::PHPToExcel($entry->entry_date),
            'vendor' => $entry->vendor,
            'category' => $entry->category?->name,
            'debit_account' => $debit?->account?->name,
            'debit_amount' => $debit?->amount_yen,
            'credit_account' => $credit?->account?->name,
            'credit_amount' => $credit?->amount_yen,
            'description' => $entry->description,
            'status' => $entry->status->label(),
            'confirmed_by' => $entry->confirmer?->name,
            'confirmed_at' => $entry->confirmed_at === null ? null : Date::PHPToExcel($entry->confirmed_at),
            'ai' => match (true) {
                $entry->ai_run_id === null => '手入力',
                in_array(ConcernFlag::Corrected->value, $entry->concern_flags ?? [], true)
                    || AiCorrection::query()->where('journal_entry_id', $entry->id)->exists() => '直した',
                default => 'AIのまま',
            },
        ];

        foreach ($workbook->layout as $index => $key) {
            $column = $index + 1;
            $value = $values[$key] ?? null;
            $sheet->setCellValueExplicit([$column, $row], $value, match (true) {
                $value === null => DataType::TYPE_NULL,
                is_int($value), is_float($value) => DataType::TYPE_NUMERIC,
                default => DataType::TYPE_STRING,
            });

            $format = match ($key) {
                'date' => 'yyyy/m/d',
                'confirmed_at' => 'yyyy/m/d h:mm',
                'debit_amount', 'credit_amount' => '#,##0',
                default => null,
            };
            if ($format !== null) {
                $sheet->getStyle([$column, $row])->getNumberFormat()->setFormatCode($format);
            }
        }
    }
}
