<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\EntryStatus;
use App\Enums\Side;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\TrialBalance;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 会社の確定した仕訳（開始仕訳を含む）を集めて、月の試算表（App\Support\TrialBalance）を出す（#65）。
 * Excel は閲覧のみの人も落とせる帳簿（WorkbookService）には混ぜず、試算表と開始仕訳の2シートの別ファイルにする。
 */
class TrialBalanceReport
{
    public const TRIAL_SHEET = '試算表';

    public const OPENING_SHEET = '開始仕訳';

    public const NO_OPENING_NOTE = '使い始めてからの増減です。残高ではありません';

    /**
     * @return array{month: string, fiscal_year_start: string, has_opening: bool, rows: list<array{account_id: string, code: string, name: string, type: string, carried: int, debit: int, credit: int, closing: int}>, debit_total: int, credit_total: int, balanced: bool, problem: string|null}
     */
    public function forMonth(Company $company, string $month): array
    {
        $monthEnd = CarbonImmutable::createFromFormat('!Y-m', $month)?->endOfMonth()->toDateString() ?? $month;

        $accounts = array_values($company->accounts()->orderBy('code')->get()
            ->map(fn (Account $account): array => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type->value])
            ->values()->all());

        $entries = array_values($company->ledgerEntries()
            ->where('status', EntryStatus::Confirmed->value)
            ->whereDate('entry_date', '<=', $monthEnd)
            ->with('lines')
            ->get()
            ->map(fn (JournalEntry $entry): array => [
                'date' => $entry->entry_date->toDateString(),
                'kind' => $entry->kind->value,
                'status' => $entry->status->value,
                'lines' => array_values($entry->lines->map(fn (JournalLine $line): array => [
                    'account_id' => $line->account_id,
                    'side' => $line->side->value,
                    'amount' => $line->amount_yen,
                ])->values()->all()),
            ])->values()->all());

        return TrialBalance::compute($accounts, $entries, $month, $company->fiscal_year_end_month);
    }

    public function spreadsheet(Company $company, string $month): string
    {
        $result = $this->forMonth($company, $month);

        $spreadsheet = new Spreadsheet;
        $trial = $spreadsheet->getActiveSheet()->setTitle(self::TRIAL_SHEET);
        $this->writeTrialSheet($trial, $company, $result);
        $this->writeOpeningSheet($spreadsheet->createSheet()->setTitle(self::OPENING_SHEET), $company);
        $spreadsheet->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'tb');
        try {
            (new Xlsx($spreadsheet))->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  array{month: string, fiscal_year_start: string, has_opening: bool, rows: list<array{account_id: string, code: string, name: string, type: string, carried: int, debit: int, credit: int, closing: int}>, debit_total: int, credit_total: int, balanced: bool, problem: string|null}  $result
     */
    private function writeTrialSheet(Worksheet $sheet, Company $company, array $result): void
    {
        $this->put($sheet, 1, 1, CarbonImmutable::createFromFormat('!Y-m', $result['month'])?->isoFormat('YYYY年M月').'の試算表（アプリに登録した仕訳の分）');
        $this->put($sheet, 1, 2, $result['balanced'] ? '借方と貸方は合っています' : '合っていません');
        $this->put($sheet, 1, 3, $result['has_opening'] ? '開始仕訳を含みます' : self::NO_OPENING_NOTE);

        $headers = ['科目コード', '科目', '区分', '前月まで', '借方', '貸方', '月末の残高'];
        foreach ($headers as $i => $header) {
            $this->put($sheet, $i + 1, 5, $header);
        }
        $sheet->getStyle([1, 5, count($headers), 5])->getFont()->setBold(true);

        $row = 6;
        foreach ($result['rows'] as $line) {
            $values = [$line['code'], $line['name'], AccountType::from($line['type'])->label(), $line['carried'], $line['debit'], $line['credit'], $line['closing']];
            foreach ($values as $i => $value) {
                $this->put($sheet, $i + 1, $row, $value);
            }
            $row++;
        }
        $this->put($sheet, 2, $row, '合計');
        $this->put($sheet, 5, $row, $result['debit_total']);
        $this->put($sheet, 6, $row, $result['credit_total']);
        $sheet->getStyle([4, 6, 7, $row])->getNumberFormat()->setFormatCode('#,##0');
    }

    private function writeOpeningSheet(Worksheet $sheet, Company $company): void
    {
        $headers = ['日付', '科目コード', '科目', '借方', '貸方'];
        foreach ($headers as $i => $header) {
            $this->put($sheet, $i + 1, 1, $header);
        }
        $sheet->getStyle([1, 1, count($headers), 1])->getFont()->setBold(true);

        /** @var JournalEntry|null $opening */
        $opening = $company->openingEntries()->with('lines.account')->first();
        if ($opening === null) {
            $this->put($sheet, 1, 2, 'まだ入れていません');

            return;
        }

        $row = 2;
        foreach ($opening->lines->sortBy('line_no') as $line) {
            /** @var JournalLine $line */
            $this->put($sheet, 1, $row, $opening->entry_date->format('Y/m/d'));
            $this->put($sheet, 2, $row, $line->account->code);
            $this->put($sheet, 3, $row, $line->account->name);
            $this->put($sheet, $line->side === Side::Debit ? 4 : 5, $row, $line->amount_yen);
            $row++;
        }
        $sheet->getStyle([4, 2, 5, max(2, $row - 1)])->getNumberFormat()->setFormatCode('#,##0');
    }

    private function put(Worksheet $sheet, int $column, int $row, string|int $value): void
    {
        // 科目名などの文字は数式として読ませない
        $sheet->setCellValueExplicit([$column, $row], $value, is_int($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
    }
}
