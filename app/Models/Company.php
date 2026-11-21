<?php

namespace App\Models;

use App\Enums\EntryKind;
use App\Support\CustomerConcentration;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property int $fiscal_year_end_month
 * @property Carbon|null $bookkeeping_start_date
 * @property bool $is_demo
 * @property int $concern_amount_yen
 * @property int|null $approval_required_yen
 * @property list<int> $busy_months
 * @property int $customer_concentration_alert_pct
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'fiscal_year_end_month', 'bookkeeping_start_date', 'is_demo', 'concern_amount_yen'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasUuids;

    /** 社長の見立ての忙しい月（初期値） */
    public const DEFAULT_BUSY_MONTHS = [6, 7, 10, 11, 12];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'busy_months' => '[6,7,10,11,12]',
        'customer_concentration_alert_pct' => CustomerConcentration::DEFAULT_ALERT_PCT,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fiscal_year_end_month' => 'integer',
            'bookkeeping_start_date' => 'date',
            'is_demo' => 'boolean',
            'concern_amount_yen' => 'integer',
            'approval_required_yen' => 'integer',
            'busy_months' => 'array',
            'customer_concentration_alert_pct' => 'integer',
        ];
    }

    /**
     * 取引先への依存の知らせの割合（#75）。1〜100 の整数でなければ初期値（70）を使う。
     * 反映の途中で列がまだないとき・DB を直接触って範囲の外が入ったときに、社長の画面を止めないため。
     */
    public function concentrationAlertPct(): int
    {
        $pct = $this->getAttribute('customer_concentration_alert_pct');

        return is_int($pct) && $pct >= CustomerConcentration::MIN_ALERT_PCT && $pct <= CustomerConcentration::MAX_ALERT_PCT
            ? $pct
            : CustomerConcentration::DEFAULT_ALERT_PCT;
    }

    public function isBusyMonth(int $month): bool
    {
        return in_array($month, $this->busy_months, true);
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')
            ->withPivot(['id', 'role', 'submit_only', 'disabled_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * @return HasMany<BankAccount, $this>
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    /**
     * @return HasMany<BankBalance, $this>
     */
    public function bankBalances(): HasMany
    {
        return $this->hasMany(BankBalance::class);
    }

    /**
     * 取引先（#67）。本当の名前を持つので、予定の画面と社長の画面（manageSchedules の人だけ）のほかでは引かない。
     *
     * @return HasMany<BusinessPartner, $this>
     */
    public function businessPartners(): HasMany
    {
        return $this->hasMany(BusinessPartner::class);
    }

    /**
     * 入金・支払いの予定（#67）。
     *
     * @return HasMany<CashSchedule, $this>
     */
    public function cashSchedules(): HasMany
    {
        return $this->hasMany(CashSchedule::class);
    }

    /**
     * @return HasMany<CategoryRule, $this>
     */
    public function categoryRules(): HasMany
    {
        return $this->hasMany(CategoryRule::class);
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * @return HasMany<Evidence, $this>
     */
    public function evidences(): HasMany
    {
        return $this->hasMany(Evidence::class);
    }

    /**
     * @return HasMany<SourceRecord, $this>
     */
    public function sourceRecords(): HasMany
    {
        return $this->hasMany(SourceRecord::class);
    }

    /**
     * 通常の仕訳だけ。開始仕訳（#65）は一覧・社長の画面・Excel の仕訳帳・分析用の書き出し・率に入れないため、ここで外す。
     *
     * @return HasMany<JournalEntry, $this>
     */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class)->where('kind', EntryKind::Normal->value);
    }

    /**
     * 開始仕訳（期首の残高。会社に1件まで）。入れる・直すのは OpeningEntryService だけ。
     *
     * @return HasMany<JournalEntry, $this>
     */
    public function openingEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class)->where('kind', EntryKind::Opening->value);
    }

    /**
     * 通常の仕訳と開始仕訳の両方。試算表（TrialBalanceReport）だけが使う。
     *
     * @return HasMany<JournalEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    /**
     * @return HasMany<AiRun, $this>
     */
    public function aiRuns(): HasMany
    {
        return $this->hasMany(AiRun::class);
    }

    /**
     * @return HasMany<AiCorrection, $this>
     */
    public function aiCorrections(): HasMany
    {
        return $this->hasMany(AiCorrection::class);
    }

    /**
     * @return HasMany<FirstConfirmation, $this>
     */
    public function firstConfirmations(): HasMany
    {
        return $this->hasMany(FirstConfirmation::class);
    }

    /**
     * @return HasMany<EntryPostEvent, $this>
     */
    public function entryPostEvents(): HasMany
    {
        return $this->hasMany(EntryPostEvent::class);
    }

    /**
     * @return HasMany<ExternalMonthlyFigure, $this>
     */
    public function externalMonthlyFigures(): HasMany
    {
        return $this->hasMany(ExternalMonthlyFigure::class);
    }

    /**
     * @return HasMany<ReportImport, $this>
     */
    public function reportImports(): HasMany
    {
        return $this->hasMany(ReportImport::class);
    }

    /**
     * @return HasMany<ExternalCustomerSale, $this>
     */
    public function externalCustomerSales(): HasMany
    {
        return $this->hasMany(ExternalCustomerSale::class);
    }

    /**
     * @return HasMany<OwnerInsight, $this>
     */
    public function ownerInsights(): HasMany
    {
        return $this->hasMany(OwnerInsight::class);
    }

    /**
     * @return HasOne<Workbook, $this>
     */
    public function workbook(): HasOne
    {
        return $this->hasOne(Workbook::class);
    }
}
