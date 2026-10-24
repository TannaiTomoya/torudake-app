<?php

namespace App\Services\Ai;

use App\Enums\AiTask;

/**
 * 開発・自動テスト用。決まった JSON を返し、外へは通信しない。
 */
class FakeClaudeClient implements AiClient
{
    /** @var array<string, string> タスクの値 → error / invalid */
    private array $failures = [];

    /** @var list<AiRequest> */
    public array $requests = [];

    public function failWith(AiTask $task, string $mode = 'error'): void
    {
        $this->failures[$task->value] = $mode;
    }

    public function complete(AiRequest $request): AiResult
    {
        $this->requests[] = $request;

        $mode = $this->failures[$request->task->value] ?? null;

        if ($mode === 'error') {
            throw new AiException('AIがエラーを返しました（偽物）');
        }

        if ($mode === 'invalid') {
            return new AiResult(output: ['unexpected' => true], inputTokens: 10, outputTokens: 5);
        }

        $output = match ($request->task) {
            AiTask::ReadReceipt => $this->readReceipt(),
            AiTask::Journalize => $this->journalize($request->input),
            AiTask::CheckEntry => $this->checkEntry($request->input),
            default => [],
        };

        return new AiResult(output: $output, inputTokens: 1200, outputTokens: 300);
    }

    public function driverName(): string
    {
        return 'fake';
    }

    public function modelName(): string
    {
        return 'fake';
    }

    /**
     * @return array<string, mixed>
     */
    private function readReceipt(): array
    {
        return [
            'full_text' => "〇〇石油 八幡平店\n軽油 25.0L\n合計 3,850円",
            'date' => now()->toDateString(),
            'vendor' => '〇〇石油 八幡平店',
            'total_yen' => 3850,
            'tax_breakdown' => [
                ['rate' => 10, 'amount_yen' => 3850],
            ],
            'registration_number' => null,
            'confidence' => ['date' => 0.95, 'vendor' => 0.9, 'total' => 0.97],
        ];
    }

    /**
     * 直した例に同じ店があればその分類を使い、なければわざと「消耗品」にする（デモの見せ場 S3）。
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function journalize(array $input): array
    {
        /** @var array<string, mixed> $reading */
        $reading = is_array($input['reading'] ?? null) ? $input['reading'] : [];
        $vendor = is_string($reading['vendor'] ?? null) ? $reading['vendor'] : null;
        $amount = is_int($reading['total_yen'] ?? null) ? $reading['total_yen'] : 1;

        $categoryName = '消耗品';
        $remembered = false;

        foreach (is_array($input['corrections'] ?? null) ? $input['corrections'] : [] as $correction) {
            if (is_array($correction)
                && ($correction['field'] ?? null) === 'category'
                && $vendor !== null
                && ($correction['vendor'] ?? null) === $vendor
                && is_string($correction['after'] ?? null)) {
                $categoryName = $correction['after'];
                $remembered = true;
                break;
            }
        }

        $debitCode = null;
        foreach (is_array($input['categories'] ?? null) ? $input['categories'] : [] as $category) {
            if (is_array($category) && ($category['name'] ?? null) === $categoryName) {
                $debitCode = $category['account_code'] ?? null;
            }
        }

        $creditCode = null;
        foreach (is_array($input['bank_accounts'] ?? null) ? $input['bank_accounts'] : [] as $bankAccount) {
            if (is_array($bankAccount) && ($bankAccount['default'] ?? false) === true) {
                $creditCode = $bankAccount['account_code'] ?? null;
            }
        }

        return [
            'category' => $categoryName,
            'description' => ($vendor ?? '取引先不明').'　'.$categoryName,
            'vendor' => $vendor,
            'lines' => [
                ['side' => 'debit', 'account_code' => (string) $debitCode, 'amount_yen' => $amount],
                ['side' => 'credit', 'account_code' => (string) $creditCode, 'amount_yen' => $amount],
            ],
            'reason' => $remembered ? '前回、同じ店を直した例があるため' : '品目から消耗品と判断したため',
            'confidence' => $remembered ? 0.92 : 0.55,
            'remembered' => $remembered,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function checkEntry(array $input): array
    {
        $debit = 0;
        $credit = 0;

        foreach (is_array($input['lines'] ?? null) ? $input['lines'] : [] as $line) {
            if (! is_array($line) || ! is_int($line['amount_yen'] ?? null)) {
                continue;
            }

            if (($line['side'] ?? null) === 'debit') {
                $debit += $line['amount_yen'];
            } else {
                $credit += $line['amount_yen'];
            }
        }

        return [
            'debit_total' => $debit,
            'credit_total' => $credit,
            'balanced' => $debit === $credit,
            'concerns' => [],
        ];
    }
}
