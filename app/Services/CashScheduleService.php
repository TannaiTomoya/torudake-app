<?php

namespace App\Services;

use App\Enums\BankAccountKind;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\BusinessPartner;
use App\Models\CashSchedule;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SensitiveParameter;

/**
 * 取引先と、入金・支払いの予定を入れる・直す・済みにする・取り消す（#67）。AI は使わない。
 *
 * - 取引先の本当の名前は business_partners.name にだけ書く。監査記録には ID と操作だけを残し、名前は書かない
 * - 給料は全員の合計の1行だけ。取引先は付けられない
 * - 済みの予定は直せず、取り消せない（先に「済みを戻す」）
 * - 権限（manageSchedules）は、呼ぶ側（Livewire のアクション）が毎回確かめる
 * - どの操作も、最初に会社の行を lockForUpdate() する（ロックの順番をそろえて、同時に押されても待ち合いにならないようにする）
 * - 取引先の名前が、エラーの文（ログ）に入らないようにする（SQL のエラーは包み直し、引数は SensitiveParameter）
 */
class CashScheduleService
{
    /** 予定の金額の上限（円）。1兆円 */
    public const MAX_YEN = 1_000_000_000_000;

    /** 取引先の名前の長さの上限（文字） */
    public const MAX_PARTNER_NAME = 60;

    /** 予定日は、今日から何年先まで入れられるか */
    public const MAX_YEARS_AHEAD = 5;

    private const MIN_DATE = '2000-01-01';

    /** ほかの処理とロックが待ち合いになったときに、やり直す回数 */
    private const ATTEMPTS = 3;

    public function addPartner(Company $company, User $user, #[SensitiveParameter] string $name): BusinessPartner
    {
        $name = $this->partnerName($name);

        return $this->partnerTransaction(function () use ($company, $user, $name): BusinessPartner {
            // 同じ名前の二重登録を防ぐため、会社の行で直列にする
            $this->lockCompany($company);

            /** @var BusinessPartner|null $existing */
            $existing = $company->businessPartners()->where('name', $name)->lockForUpdate()->first();

            if ($existing !== null) {
                if ($existing->archived_at === null) {
                    throw ValidationException::withMessages(['partnerName' => '同じ名前の取引先がすでにあります。']);
                }

                $existing->forceFill(['archived_at' => null])->save();
                AuditLog::record(user: $user, action: 'business_partner.restore', subject: $existing, before: ['archived' => true], after: ['archived' => false], company: $company);

                return $existing;
            }

            $partner = new BusinessPartner;
            $partner->forceFill(['company_id' => $company->id, 'name' => $name])->save();
            AuditLog::record(user: $user, action: 'business_partner.add', subject: $partner, after: ['archived' => false], company: $company);

            return $partner;
        });
    }

    public function renamePartner(Company $company, User $user, string $partnerId, #[SensitiveParameter] string $name): BusinessPartner
    {
        $name = $this->partnerName($name, 'renameName');

        return $this->partnerTransaction(function () use ($company, $user, $partnerId, $name): BusinessPartner {
            $this->lockCompany($company);

            /** @var BusinessPartner $partner */
            $partner = $company->businessPartners()->lockForUpdate()->findOrFail($partnerId);

            if ($partner->name === $name) {
                return $partner;
            }
            if ($company->businessPartners()->where('name', $name)->whereKeyNot($partner->id)->exists()) {
                throw ValidationException::withMessages(['renameName' => '同じ名前の取引先がすでにあります（使わなくした取引先も含みます）。']);
            }

            $partner->forceFill(['name' => $name])->save();
            AuditLog::record(user: $user, action: 'business_partner.rename', subject: $partner, after: ['name_changed' => true], company: $company);

            return $partner;
        });
    }

    public function archivePartner(Company $company, User $user, string $partnerId): BusinessPartner
    {
        return $this->partnerTransaction(function () use ($company, $user, $partnerId): BusinessPartner {
            $this->lockCompany($company);

            /** @var BusinessPartner $partner */
            $partner = $company->businessPartners()->lockForUpdate()->findOrFail($partnerId);

            if ($partner->archived_at !== null) {
                return $partner;
            }

            $partner->forceFill(['archived_at' => now()])->save();
            AuditLog::record(user: $user, action: 'business_partner.archive', subject: $partner, before: ['archived' => false], after: ['archived' => true], company: $company);

            return $partner;
        });
    }

    public function add(Company $company, User $user, CashScheduleInput $input): CashSchedule
    {
        return DB::transaction(function () use ($company, $user, $input): CashSchedule {
            $this->lockCompany($company);
            $values = $this->checked($company, $input, null);

            $schedule = new CashSchedule;
            $schedule->forceFill([...$values, 'company_id' => $company->id, 'created_by' => $user->id])->save();
            AuditLog::record(user: $user, action: 'cash_schedule.add', subject: $schedule, after: $this->auditValues($schedule), company: $company);

            return $schedule;
        }, self::ATTEMPTS);
    }

    public function update(Company $company, User $user, string $scheduleId, CashScheduleInput $input): CashSchedule
    {
        return DB::transaction(function () use ($company, $user, $scheduleId, $input): CashSchedule {
            $this->lockCompany($company);

            /** @var CashSchedule $schedule */
            $schedule = $company->cashSchedules()->lockForUpdate()->findOrFail($scheduleId);

            if ($schedule->settled_on !== null) {
                throw ValidationException::withMessages(['schedule' => '済みの予定は直せません。先に「済みを戻す」を押してください。']);
            }

            $values = $this->checked($company, $input, $schedule);
            $before = $this->auditValues($schedule);
            $schedule->forceFill($values);

            if (! $schedule->isDirty()) {
                return $schedule;
            }

            $schedule->save();
            AuditLog::record(user: $user, action: 'cash_schedule.update', subject: $schedule, before: $before, after: $this->auditValues($schedule), company: $company);

            return $schedule;
        }, self::ATTEMPTS);
    }

    public function settle(Company $company, User $user, string $scheduleId, CarbonImmutable $settledOn): CashSchedule
    {
        return DB::transaction(function () use ($company, $user, $scheduleId, $settledOn): CashSchedule {
            $this->lockCompany($company);

            /** @var CashSchedule $schedule */
            $schedule = $company->cashSchedules()->lockForUpdate()->findOrFail($scheduleId);

            if ($schedule->settled_on !== null) {
                return $schedule;
            }
            if ($settledOn->greaterThan(CarbonImmutable::today()) || $settledOn->toDateString() < self::MIN_DATE) {
                throw ValidationException::withMessages(['settleDate' => '済みの日は、今日までの日付で入れてください。']);
            }

            $before = $this->auditValues($schedule);
            $schedule->forceFill(['settled_on' => $settledOn->toDateString(), 'settled_by' => $user->id])->save();
            AuditLog::record(user: $user, action: 'cash_schedule.settle', subject: $schedule, before: $before, after: $this->auditValues($schedule), company: $company);

            return $schedule;
        }, self::ATTEMPTS);
    }

    public function unsettle(Company $company, User $user, string $scheduleId): CashSchedule
    {
        return DB::transaction(function () use ($company, $user, $scheduleId): CashSchedule {
            $this->lockCompany($company);

            /** @var CashSchedule $schedule */
            $schedule = $company->cashSchedules()->lockForUpdate()->findOrFail($scheduleId);

            if ($schedule->settled_on === null) {
                return $schedule;
            }

            $before = $this->auditValues($schedule);
            $schedule->forceFill(['settled_on' => null, 'settled_by' => null])->save();
            AuditLog::record(user: $user, action: 'cash_schedule.unsettle', subject: $schedule, before: $before, after: $this->auditValues($schedule), company: $company);

            return $schedule;
        }, self::ATTEMPTS);
    }

    public function remove(Company $company, User $user, string $scheduleId): void
    {
        DB::transaction(function () use ($company, $user, $scheduleId): void {
            $this->lockCompany($company);

            /** @var CashSchedule $schedule */
            $schedule = $company->cashSchedules()->lockForUpdate()->findOrFail($scheduleId);

            if ($schedule->settled_on !== null) {
                throw ValidationException::withMessages(['schedule' => '済みの予定は取り消せません。先に「済みを戻す」を押してください。']);
            }

            AuditLog::record(user: $user, action: 'cash_schedule.delete', subject: $schedule, before: $this->auditValues($schedule), company: $company);
            $schedule->delete();
        }, self::ATTEMPTS);
    }

    /**
     * 会社の行をロックする。この係のどの操作も最初にこれを呼び、同じ会社の予定・取引先の書き込みを1つずつにする。
     */
    private function lockCompany(Company $company): void
    {
        Company::query()->lockForUpdate()->findOrFail($company->id);
    }

    /**
     * 取引先を書き換える処理。SQL のエラーの文には入れた値（＝取引先の名前）が入るので、
     * 名前を含まない例外に包み直してから投げる（もとの例外は付けない。ログに名前を残さないため）。
     *
     * @param  Closure(): BusinessPartner  $work
     */
    private function partnerTransaction(Closure $work): BusinessPartner
    {
        try {
            return DB::transaction($work, self::ATTEMPTS);
        } catch (QueryException $e) {
            throw new RuntimeException('取引先を保存できませんでした（データベースのエラー：'.$e->getCode().'）。');
        }
    }

    /**
     * 入力を確かめて、保存する値にする。口座と取引先は会社から引き、行を lockForUpdate() してから確かめる（他社は404）。
     *
     * @return array{direction: string, category: string, business_partner_id: string|null, bank_account_id: string, amount_yen: int, due_date: string}
     */
    private function checked(Company $company, CashScheduleInput $input, ?CashSchedule $current): array
    {
        if ($input->category->direction() !== $input->direction) {
            throw ValidationException::withMessages(['category' => '種類を選び直してください。']);
        }
        if ($input->amountYen < 1 || $input->amountYen > self::MAX_YEN) {
            throw ValidationException::withMessages(['amountYen' => '金額は1円から1兆円までの数字で入れてください。']);
        }

        $due = $input->dueDate->toDateString();
        if ($due < self::MIN_DATE || $due > CarbonImmutable::today()->addYears(self::MAX_YEARS_AHEAD)->toDateString()) {
            throw ValidationException::withMessages(['dueDate' => '予定日は、2000年から'.self::MAX_YEARS_AHEAD.'年先までの日付で入れてください。']);
        }

        /** @var BankAccount $account */
        $account = $company->bankAccounts()->lockForUpdate()->findOrFail($input->bankAccountId);
        if ($account->kind !== BankAccountKind::Bank || $account->archived_at !== null) {
            throw ValidationException::withMessages(['bankAccountId' => '予定を入れられるのは、使っている銀行の口座だけです。']);
        }

        $partnerId = $input->partnerId === '' ? null : $input->partnerId;
        if ($partnerId !== null) {
            if (! $input->category->allowsPartner()) {
                throw ValidationException::withMessages(['partnerId' => '給料は全員の合計で入れます。取引先や人の名前は付けられません。']);
            }

            /** @var BusinessPartner $partner */
            $partner = $company->businessPartners()->lockForUpdate()->findOrFail($partnerId);
            if ($partner->archived_at !== null && $current?->business_partner_id !== $partner->id) {
                throw ValidationException::withMessages(['partnerId' => 'この取引先は使わなくしてあります。']);
            }
        }

        return [
            'direction' => $input->direction->value,
            'category' => $input->category->value,
            'business_partner_id' => $partnerId,
            'bank_account_id' => $account->id,
            'amount_yen' => $input->amountYen,
            'due_date' => $due,
        ];
    }

    /**
     * 取引先の名前を整える（前後と重なった空白を取る）。空・長すぎ・改行などは受け付けない。
     */
    private function partnerName(#[SensitiveParameter] string $name, string $key = 'partnerName'): string
    {
        $name = trim((string) preg_replace('/[\s　]+/u', ' ', $name));

        if ($name === '') {
            throw ValidationException::withMessages([$key => '取引先の名前を入れてください。']);
        }
        if (preg_match('/[\p{Cc}\p{Cf}]/u', $name) === 1) {
            throw ValidationException::withMessages([$key => '取引先の名前に、改行や見えない文字が入っています。入れ直してください。']);
        }
        if (mb_strlen($name) > self::MAX_PARTNER_NAME) {
            throw ValidationException::withMessages([$key => '取引先の名前は'.self::MAX_PARTNER_NAME.'文字までで入れてください。']);
        }

        return $name;
    }

    /**
     * 監査記録に残す値。取引先は ID だけ（名前は書かない）。
     *
     * @return array{direction: string, category: string, business_partner_id: string|null, bank_account_id: string, amount_yen: int, due_date: string, settled_on: string|null}
     */
    private function auditValues(CashSchedule $schedule): array
    {
        return [
            'direction' => $schedule->direction->value,
            'category' => $schedule->category->value,
            'business_partner_id' => $schedule->business_partner_id,
            'bank_account_id' => $schedule->bank_account_id,
            'amount_yen' => $schedule->amount_yen,
            'due_date' => $schedule->due_date->toDateString(),
            'settled_on' => $schedule->settled_on?->toDateString(),
        ];
    }
}
