<?php

namespace App\Support;

use App\Enums\AiTask;
use App\Enums\EntryStatus;
use App\Models\Company;
use App\Models\FirstConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * 「下書きに手を入れなかった率」などの定義の正本（#32）。画面・書き出しの manifest・後続の分析が、この定義と同じ母数・分子を使う。
 *
 * 測るのは行動（下書きに手を入れたか）で、AI の答えが会計として正しかった割合ではない。
 * - 母数：その月（会社の時刻）に**初回確定**した、AI が下書きを作った仕訳。再確定・戻しでは増えない。月をまたいで二重に数えない。
 * - 手を入れなかった：必須項目（日付を読めた・金額の記載がある）が揃い、日付・店名・分類・支払い元・金額のどれも変えず、記載のなかった欄も人が埋めていない。摘要は自由文なので含めず、別に数える。
 * - fake・replay・デモ会社は実際の AI の数字と混ぜない。会社が実際なら real、デモ会社なら demo だけを出し、もう一方は件数だけ知らせる。
 * - 記録のない過去の仕訳（この機能の前に確定したもの）は評価不能として件数だけ出し、率には入れない。
 */
final class FirstConfirmationMetrics
{
    public const DEFINITION_VERSION = 'first-confirmation.v1';

    /**
     * @return array{
     *     definition_version: string,
     *     class: string,
     *     ai_first: int,
     *     unchanged: int,
     *     unchanged_pct: int|null,
     *     amount_filled: int,
     *     amount_filled_pct: int|null,
     *     description_changed: int,
     *     description_changed_pct: int|null,
     *     history_applied: int,
     *     without_history: array{total: int, unchanged: int, pct: int|null},
     *     manual: int,
     *     manual_pct: int|null,
     *     post_corrected: int,
     *     post_corrected_pct: int|null,
     *     recommendation: array{with_candidate: int, adopted: int, adopted_pct: int|null, rejected: int, rejected_pct: int|null, held: int, held_pct: int|null, adopted_then_corrected: int, expired: int, no_candidate: int, review_seconds: null},
     *     ai_runs: int,
     *     ai_failed: int,
     *     ai_failed_pct: int|null,
     *     legacy: int,
     *     other_class: int,
     * }
     */
    public static function for(Company $company, CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $to = $from->addMonth();
        $class = $company->is_demo ? FirstConfirmation::CLASS_DEMO : FirstConfirmation::CLASS_REAL;

        $period = fn () => $company->firstConfirmations()
            ->where('first_confirmed_at', '>=', $from)
            ->where('first_confirmed_at', '<', $to);

        $rows = $period()->where('evaluation_class', $class)->get();
        $ai = $rows->where('kind', FirstConfirmation::KIND_AI);
        $manual = $rows->where('kind', FirstConfirmation::KIND_MANUAL)->count();

        $unchanged = $ai->filter(fn (FirstConfirmation $row): bool => $row->isUnchanged());
        $withoutHistory = $ai->filter(fn (FirstConfirmation $row): bool => ($row->draft_origin['payment'] ?? null) !== 'history');

        $postCorrected = $company->entryPostEvents()
            ->whereIn('journal_entry_id', $period()->where('evaluation_class', $class)->where('kind', FirstConfirmation::KIND_AI)->select('journal_entry_id'))
            ->distinct()
            ->count('journal_entry_id');

        $runs = $company->aiRuns()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->whereIn('task', [AiTask::ReadReceipt, AiTask::Journalize])
            ->when(
                $class === FirstConfirmation::CLASS_REAL,
                fn ($query) => $query->where('driver', 'claude'),
                fn ($query) => $company->is_demo ? $query : $query->where('driver', '!=', 'claude'),
            );
        $aiRuns = (clone $runs)->count();
        $aiFailed = (clone $runs)->where('valid', false)->count();

        $legacy = $company->journalEntries()
            ->whereNotNull('ai_run_id')
            ->whereIn('status', [EntryStatus::Confirmed, EntryStatus::Returned])
            ->where('confirmed_at', '>=', $from)
            ->where('confirmed_at', '<', $to)
            ->whereNotIn('id', $company->firstConfirmations()->select('journal_entry_id'))
            ->count();

        $total = $ai->count();

        return [
            'definition_version' => self::DEFINITION_VERSION,
            'class' => $class,
            'ai_first' => $total,
            'unchanged' => $unchanged->count(),
            'unchanged_pct' => self::pct($unchanged->count(), $total),
            'amount_filled' => $ai->filter(fn (FirstConfirmation $row): bool => in_array('amount', $row->filled_fields, true))->count(),
            'amount_filled_pct' => self::pct($ai->filter(fn (FirstConfirmation $row): bool => in_array('amount', $row->filled_fields, true))->count(), $total),
            'description_changed' => $ai->where('description_changed', true)->count(),
            'description_changed_pct' => self::pct($ai->where('description_changed', true)->count(), $total),
            'history_applied' => $total - $withoutHistory->count(),
            'without_history' => [
                'total' => $withoutHistory->count(),
                'unchanged' => $withoutHistory->filter(fn (FirstConfirmation $row): bool => $row->isUnchanged())->count(),
                'pct' => self::pct($withoutHistory->filter(fn (FirstConfirmation $row): bool => $row->isUnchanged())->count(), $withoutHistory->count()),
            ],
            'manual' => $manual,
            'manual_pct' => self::pct($manual, $total + $manual),
            'post_corrected' => $postCorrected,
            'post_corrected_pct' => self::pct($postCorrected, $total),
            'recommendation' => self::recommendation($company, $ai),
            'ai_runs' => $aiRuns,
            'ai_failed' => $aiFailed,
            'ai_failed_pct' => self::pct($aiFailed, $aiRuns),
            'legacy' => $legacy,
            'other_class' => $period()->where('evaluation_class', '!=', $class)->count(),
        ];
    }

    /**
     * 履歴からの候補（#54）。採用率だけでなく、別を選んだ・保留・採用したあとの事後修正・根拠の失効を並べる。
     * 母数は「候補があった（採用・別を選んだ・保留）」初回確定。候補がなかったものは失敗ではないので、別に件数だけ出す。
     * 確認にかかった時間は、実作業の計測（#44）がまだないので null（測っていない）。この数字だけで改善したとは言わない。
     *
     * @param  Collection<int, FirstConfirmation>  $ai
     * @return array{with_candidate: int, adopted: int, adopted_pct: int|null, rejected: int, rejected_pct: int|null, held: int, held_pct: int|null, adopted_then_corrected: int, expired: int, no_candidate: int, review_seconds: null}
     */
    private static function recommendation(Company $company, $ai): array
    {
        $outcome = fn (string $name) => $ai->filter(fn (FirstConfirmation $row): bool => ($row->recommendation['outcome'] ?? null) === $name);

        $adopted = $outcome('adopted');
        $rejected = $outcome('rejected')->count();
        $held = $outcome('held')->count();
        $withCandidate = $adopted->count() + $rejected + $held;

        $adoptedThenCorrected = $adopted->isEmpty() ? 0 : $company->entryPostEvents()
            ->whereIn('journal_entry_id', $adopted->pluck('journal_entry_id'))
            ->distinct()
            ->count('journal_entry_id');

        return [
            'with_candidate' => $withCandidate,
            'adopted' => $adopted->count(),
            'adopted_pct' => self::pct($adopted->count(), $withCandidate),
            'rejected' => $rejected,
            'rejected_pct' => self::pct($rejected, $withCandidate),
            'held' => $held,
            'held_pct' => self::pct($held, $withCandidate),
            'adopted_then_corrected' => $adoptedThenCorrected,
            'expired' => $ai->filter(fn (FirstConfirmation $row): bool => ($row->recommendation['expired'] ?? false) === true)->count(),
            'no_candidate' => $ai->count() - $withCandidate,
            'review_seconds' => null,
        ];
    }

    /** 母数が0のときは null（0% や 100% にしない） */
    public static function pct(int $part, int $whole): ?int
    {
        return $whole === 0 ? null : (int) round($part / $whole * 100);
    }
}
