<?php

namespace App\Enums;

/**
 * 貸借の検算で見つかった問題（規則の検算。AI の答えとは別）。
 */
enum BalanceProblem: string
{
    case Empty = 'empty';
    case OneSided = 'one_sided';
    case InvalidSide = 'invalid_side';
    case NonPositive = 'non_positive';
    case OutOfRange = 'out_of_range';
    case Mismatch = 'mismatch';

    public function label(): string
    {
        return match ($this) {
            self::Empty => '仕訳の行がありません',
            self::OneSided => '借方か貸方のどちらかの行がありません',
            self::InvalidSide => '借方・貸方のどちらでもない行があります',
            self::NonPositive => '金額が1円未満（0円・マイナス・整数でない）の行があります',
            self::OutOfRange => '金額が扱える範囲を超えています',
            self::Mismatch => '借方と貸方の合計が合っていません',
        };
    }
}
