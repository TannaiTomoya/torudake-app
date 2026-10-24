<?php

namespace App\Enums;

enum AiTask: string
{
    case ReadReceipt = 'read_receipt';
    case Journalize = 'journalize';
    case CheckEntry = 'check_entry';
    case MonthlySummary = 'monthly_summary';
    case CloseCheck = 'close_check';
    case OwnerBrief = 'owner_brief';
    case Forecast = 'forecast';

    public function label(): string
    {
        return match ($this) {
            self::ReadReceipt => '読み取り',
            self::Journalize => '仕訳',
            self::CheckEntry => '検算',
            self::MonthlySummary => '月次集計',
            self::CloseCheck => '締めの確認',
            self::OwnerBrief => '社長へのひとこと',
            self::Forecast => '来月の見込み',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ReadReceipt => 'brand',
            self::Journalize => 'brand',
            self::CheckEntry => 'ok',
            self::MonthlySummary => 'ink',
            self::CloseCheck => 'warn',
            self::OwnerBrief, self::Forecast => 'ink',
        };
    }
}
