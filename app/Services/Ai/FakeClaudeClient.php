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

    /** @var array<string, mixed> 読み取りの結果を差し替える欄 */
    private array $readingOverrides = [];

    /** @var array<string, mixed> */
    private array $journalizeOverrides = [];

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function withReading(array $overrides): void
    {
        $this->readingOverrides = $overrides;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function withJournalize(array $overrides): void
    {
        $this->journalizeOverrides = $overrides;
    }

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
            AiTask::Journalize => [...$this->journalize($request->input), ...$this->journalizeOverrides],
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
            'amount_missing' => false,
            'payment_method' => 'cash',
            'tax_breakdown' => [
                ['rate' => 10, 'amount_yen' => 3850],
            ],
            'registration_number' => null,
            'confidence' => ['date' => 0.95, 'vendor' => 0.9, 'total' => 0.97],
            ...$this->readingOverrides,
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

        $categoryName = '消耗品';
        $rememberedPayment = null;
        $remembered = false;

        foreach (is_array($input['corrections'] ?? null) ? $input['corrections'] : [] as $correction) {
            if (! is_array($correction) || $vendor === null || ($correction['vendor'] ?? null) !== $vendor || ! is_string($correction['after'] ?? null)) {
                continue;
            }

            if (($correction['field'] ?? null) === 'category' && $categoryName === '消耗品' && ! $remembered) {
                $categoryName = $correction['after'];
                $remembered = true;
            } elseif (($correction['field'] ?? null) === 'credit_account' && $rememberedPayment === null) {
                $rememberedPayment = $correction['after'];
                $remembered = true;
            }
        }

        $paymentMethod = $reading['payment_method'] ?? null;
        $wantedKind = match ($paymentMethod) {
            'invoice_later' => 'invoice',
            'card' => 'card',
            default => null,
        };

        $bankAccountName = '';
        foreach (is_array($input['bank_accounts'] ?? null) ? $input['bank_accounts'] : [] as $bankAccount) {
            if (! is_array($bankAccount) || ! is_string($bankAccount['name'] ?? null)) {
                continue;
            }

            if ($wantedKind !== null ? ($bankAccount['kind'] ?? null) === $wantedKind : ($bankAccount['default'] ?? false) === true) {
                $bankAccountName = $bankAccount['name'];
            }
        }
        if ($rememberedPayment !== null) {
            $bankAccountName = $rememberedPayment;
        }

        return [
            'category' => $categoryName,
            'description' => ($vendor ?? '取引先不明').'　'.$categoryName,
            'bank_account' => $bankAccountName,
            'vendor' => $vendor,
            'reason' => match (true) {
                $rememberedPayment !== null => "前回、同じ店の支払い元を{$rememberedPayment}に直したため",
                $remembered => '前回、同じ店を直した例があるため',
                default => '品目から消耗品と判断したため',
            },
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
            'duplicate_suspected' => false,
            'private_suspected' => false,
            'concerns' => [],
        ];
    }
}
