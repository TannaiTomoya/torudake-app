<?php

namespace App\Support;

/**
 * 同じ店の確定履歴から出した、分類・支払い元の候補（#54）。AI（L2）の案ではなく、この会社の人が確定した履歴だけが根拠。
 * 候補は下書きを作る材料と、経理が「今回も使う・別を選ぶ・保留」を決めるための表示に使う。採用しても確定にはならない。
 *
 * status：
 * - confident：履歴が十分で、使い方が一つにそろっている（下書きの支払い元に当ててよい）
 * - hold：履歴はあるが、決めきれない（reasons に理由）。候補は参考として出し、自動では当てない
 * - none：使える履歴がない
 *
 * @phpstan-type Candidate array{category_id: string, category_name: string, bank_account_id: string, bank_account_name: string, count: int, last_confirmed_on: string, entry_ids: list<string>}
 */
final readonly class EntryRecommendation
{
    public const CONFIDENT = 'confident';

    public const HOLD = 'hold';

    public const NONE = 'none';

    public const VERSION = 'recommendation.v1';

    /** @var array<string, string> 保留・なしの理由 => 画面の文 */
    public const REASON_LABELS = [
        'no_vendor' => '店名が分からないため',
        'few_history' => '同じ店の確認が1件しかなく、まだ参考だけにしています',
        'stale' => '最後の確認が古い（半年以上前）ため',
        'mixed_usage' => '同じ店でも、使い方が分かれているため',
        'payment_conflict' => 'レシートが後日請求なのに、過去の支払い元はそうではないため',
        'returned_in_history' => '同じ店の仕訳に、社長から戻されたものがあるため',
        'retired' => '過去に使った分類・支払い元が、いまは使われていないため',
    ];

    /**
     * @param  list<Candidate>  $candidates  多い順（最大3）
     * @param  list<string>  $reasons
     */
    public function __construct(
        public string $status,
        public array $candidates = [],
        public int $usableCount = 0,
        public array $reasons = [],
    ) {}

    /** @return Candidate|null */
    public function top(): ?array
    {
        return $this->candidates[0] ?? null;
    }

    /**
     * 下書きの支払い元に当ててよい候補（保留・なしは null）
     *
     * @return Candidate|null
     */
    public function applicable(): ?array
    {
        return $this->status === self::CONFIDENT ? $this->top() : null;
    }

    /** @return list<string> */
    public function reasonLabels(): array
    {
        return array_map(fn (string $reason): string => self::REASON_LABELS[$reason] ?? $reason, $this->reasons);
    }

    /**
     * 下書きに残す記録。候補の根拠（確定した仕訳の ID）も持ち、あとで根拠が変わったかを確かめられる。
     *
     * @param  array{category_id: string|null, bank_account_id: string|null}  $l2  AI（L2）が選んだ元の案
     * @return array<string, mixed>
     */
    public function snapshot(bool $applied, array $l2, ?string $paymentMethod): array
    {
        return [
            'version' => self::VERSION,
            'status' => $this->status,
            'reasons' => $this->reasons,
            'usable_count' => $this->usableCount,
            'candidates' => $this->candidates,
            'applied' => $applied,
            'l2' => $l2,
            'payment_method' => $paymentMethod,
        ];
    }
}
