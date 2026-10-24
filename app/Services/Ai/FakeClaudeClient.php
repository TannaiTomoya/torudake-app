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
            AiTask::ReadReceipt => $this->readReceipt($request->input),
            AiTask::Journalize => [...$this->journalize($request->input), ...$this->journalizeOverrides],
            AiTask::CheckEntry => $this->checkEntry($request->input),
            AiTask::OwnerBrief => $this->ownerBrief($request->input),
            AiTask::Forecast => $this->forecast($request->input),
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
     * 宛名は入力の会社名（自分の会社）にする。
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function readReceipt(array $input): array
    {
        return [
            'full_text' => "〇〇石油 八幡平店\n軽油 25.0L\n合計 3,850円",
            'date' => now()->toDateString(),
            'vendor' => '〇〇石油 八幡平店',
            'addressee' => is_string($input['company_name'] ?? null) ? $input['company_name'] : null,
            'addressee_kind' => 'own',
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
     * 入力の数字から決まった形で作る。忙しい月なのに前年同月より少ない月があれば busy_alert に出す。
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function ownerBrief(array $input): array
    {
        $man = fn (int $yen): string => number_format((int) round(abs($yen) / 10000)).'万円';
        $totals = is_array($input['totals'] ?? null) ? $input['totals'] : [];
        $count = is_int($totals['months_with_sales'] ?? null) ? $totals['months_with_sales'] : 0;
        $diff = is_int($totals['diff'] ?? null) ? $totals['diff'] : 0;
        $pct = is_int($totals['pct'] ?? null) ? $totals['pct'] : null;

        $headline = $count === 0
            ? 'まだ今期の売上の数字がありません'
            : "今期{$count}か月の売上は、前期の同じ月より{$man($diff)}".($pct !== null ? '（'.abs($pct).'%）' : '').($diff < 0 ? '少ない' : '多い');

        $busyAlert = null;
        foreach (is_array($input['months'] ?? null) ? $input['months'] : [] as $row) {
            if (is_array($row) && ($row['busy_but_lower'] ?? false) === true && is_int($row['yoy_diff'] ?? null) && is_int($row['month'] ?? null)) {
                $busyAlert = "{$row['month']}月は忙しい月なのに、前年同月より{$man($row['yoy_diff'])}少なかった";
                break;
            }
        }

        $customers = is_array($input['customers'] ?? null) ? $input['customers'] : [];
        $top = is_array($customers[0] ?? null) ? $customers[0] : null;

        return [
            'headline' => $headline,
            'reason' => '秋の忙しい時期に向けて、工事が増えているのかもしれません',
            'check' => $top !== null && is_string($top['label'] ?? null) && is_int($top['share_pct'] ?? null)
                ? "売上の{$top['share_pct']}%を占める{$top['label']}の、今後の工事の予定を確かめるとよい"
                : '気になる仕訳を確かめるとよい',
            'busy_alert' => $busyAlert,
        ];
    }

    /**
     * 3期の同じ月の平均×今期の伸び。範囲は±15%。経費は確定した月が2か月以上あれば平均±10%。
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function forecast(array $input): array
    {
        $round = fn (float $yen): int => (int) round($yen, -4);
        $month = is_int($input['target_month'] ?? null) ? $input['target_month'] : 1;
        $average = is_int($input['target_average'] ?? null) ? $input['target_average'] : null;
        $growth = is_int($input['growth_pct'] ?? null) ? $input['growth_pct'] : 0;
        $expenses = array_values(array_filter(is_array($input['monthly_expenses'] ?? null) ? $input['monthly_expenses'] : [], 'is_int'));

        $sales = $average === null ? null : $average * (1 + $growth / 100);
        $expense = count($expenses) >= 2 ? array_sum($expenses) / count($expenses) : null;

        return [
            'month' => $month,
            'sales' => [
                'median_yen' => $sales === null ? null : $round($sales),
                'low_yen' => $sales === null ? null : $round($sales * 0.85),
                'high_yen' => $sales === null ? null : $round($sales * 1.15),
            ],
            'expense' => [
                'median_yen' => $expense === null ? null : $round($expense),
                'low_yen' => $expense === null ? null : $round($expense * 0.9),
                'high_yen' => $expense === null ? null : $round($expense * 1.1),
            ],
            'note' => "過去3期の{$month}月の平均と、今期の伸び{$growth}%から（偽物のAI）",
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
