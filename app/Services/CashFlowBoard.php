<?php

namespace App\Services;

use App\Enums\BankAccountKind;
use App\Models\BankAccount;
use App\Models\BankBalance;
use App\Models\CashSchedule;
use App\Models\Company;
use App\Support\CashFlowForecast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * 資金繰り表を読むだけ（#68）。足し引きは CashFlowForecast の1か所だけ。
 * 取引先の名前は返さない。呼ぶ側は viewCashFlow を確かめてから使う。
 *
 * @phpstan-import-type Forecast from CashFlowForecast
 *
 * @phpstan-type AccountForecast array{
 *     account_id: string,
 *     account_name: string,
 *     missing_balance: bool,
 *     balance_date: string|null,
 *     opening_balance_yen: int|null,
 *     from: string,
 *     to: string,
 *     forecast: Forecast|null
 * }
 * @phpstan-type Shortfall array{count: int, dates: list<string>, more: int}
 */
class CashFlowBoard
{
    /** 見込みの日数（今日から約3か月） */
    public const HORIZON_MONTHS = 3;

    /** 社長の画面に並べる、0円を下回る見込みの日の件数 */
    public const OWNER_ROWS = 5;

    /**
     * 銀行で使っている口座（名前の順）。
     *
     * @return Collection<int, BankAccount>
     */
    public function accounts(Company $company): Collection
    {
        return $company->bankAccounts()
            ->where('kind', BankAccountKind::Bank->value)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * 1口座の見込み。残高がなければ missing_balance=true（0円とみなさない）。
     *
     * @return AccountForecast
     */
    public function forAccount(Company $company, string $bankAccountId, ?CarbonImmutable $today = null): array
    {
        $day = $today ?? CarbonImmutable::today();
        $from = $day->toDateString();
        $to = $day->addMonthsNoOverflow(self::HORIZON_MONTHS)->toDateString();

        $account = $company->bankAccounts()
            ->whereKey($bankAccountId)
            ->where('kind', BankAccountKind::Bank->value)
            ->whereNull('archived_at')
            ->firstOrFail();

        /** @var BankBalance|null $balance */
        $balance = $company->bankBalances()
            ->where('bank_account_id', $account->id)
            ->whereDate('balance_date', '<=', $from)
            ->orderByDesc('balance_date')
            ->orderByDesc('id')
            ->first();

        if ($balance === null) {
            return [
                'account_id' => $account->id,
                'account_name' => $account->name,
                'missing_balance' => true,
                'balance_date' => null,
                'opening_balance_yen' => null,
                'from' => $from,
                'to' => $to,
                'forecast' => null,
            ];
        }

        $items = $company->cashSchedules()
            ->where('bank_account_id', $account->id)
            ->whereNull('settled_on')
            ->whereDate('due_date', '>=', $from)
            ->whereDate('due_date', '<=', $to)
            ->get(['due_date', 'direction', 'amount_yen'])
            ->map(fn (CashSchedule $schedule): array => [
                'due_date' => $schedule->due_date->toDateString(),
                'direction' => $schedule->direction->value,
                'amount_yen' => $schedule->amount_yen,
            ])
            ->all();

        return [
            'account_id' => $account->id,
            'account_name' => $account->name,
            'missing_balance' => false,
            'balance_date' => $balance->balance_date->toDateString(),
            'opening_balance_yen' => $balance->balance_yen,
            'from' => $from,
            'to' => $to,
            'forecast' => CashFlowForecast::project($balance->balance_yen, $from, $to, $items),
        ];
    }

    /**
     * 社長の画面用。全口座の「0円を下回る見込みの日」を日付の古い順にまとめる（取引先の名前は出さない）。
     *
     * @return Shortfall
     */
    public function shortfalls(Company $company, ?CarbonImmutable $today = null): array
    {
        $dates = [];
        foreach ($this->accounts($company) as $account) {
            $row = $this->forAccount($company, $account->id, $today);
            if ($row['missing_balance'] || $row['forecast'] === null) {
                continue;
            }
            foreach ($row['forecast']['below_zero_dates'] as $date) {
                $dates[$date] = true;
            }
        }

        $sorted = array_keys($dates);
        sort($sorted);
        $count = count($sorted);

        return [
            'count' => $count,
            'dates' => array_slice($sorted, 0, self::OWNER_ROWS),
            'more' => max($count - self::OWNER_ROWS, 0),
        ];
    }
}
