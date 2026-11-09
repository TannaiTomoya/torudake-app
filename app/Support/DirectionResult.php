<?php

namespace App\Support;

use App\Enums\Side;

/**
 * 仕訳の向き（借方・貸方）の判定の結果。貸借の検算（{@see BalanceResult}）とは別の軸。
 * - consistent：判定できた行がすべて期待どおりで、判定できない行もない
 * - reversed：期待と逆の行がある（「向きを確認」。自動で反転しない・保存を止めない）
 * - undetermined：取引の意味が分からない行がある（判定保留。誤りとは言わない）
 * - invalid：行がない・借貸や勘定の種類が読めない
 */
final readonly class DirectionResult
{
    public const CONSISTENT = 'consistent';

    public const REVERSED = 'reversed';

    public const UNDETERMINED = 'undetermined';

    public const INVALID = 'invalid';

    /**
     * @param  list<array{expected: ?Side, actual: ?Side, verdict: string}>  $lines
     */
    public function __construct(
        public string $status,
        public array $lines,
    ) {}

    /**
     * 期待と逆だった行の番号（0から）。
     *
     * @return list<int>
     */
    public function reversedLines(): array
    {
        return array_keys(array_filter($this->lines, fn (array $line): bool => $line['verdict'] === self::REVERSED));
    }

    public function message(): string
    {
        return match ($this->status) {
            self::CONSISTENT => '借方・貸方の向きは取引の意味と合っています',
            self::REVERSED => '向きを確認してください：取引の意味からは逆の側に入っている行があります',
            self::UNDETERMINED => '取引の意味が分からない行があるため、向きは判定していません',
            default => '行がないか、借方・貸方・勘定の種類を読めないため、向きは判定していません',
        };
    }
}
