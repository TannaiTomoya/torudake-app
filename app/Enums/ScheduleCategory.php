<?php

namespace App\Enums;

/**
 * 予定の種類（#67）。給料は全員の合計の1行だけで、取引先を付けない（人ごとの名前・金額は持たない）。
 */
enum ScheduleCategory: string
{
    case Sales = 'sales';
    case OtherIn = 'other_in';
    case Purchase = 'purchase';
    case Salary = 'salary';
    case Lease = 'lease';
    case OtherOut = 'other_out';

    public function label(): string
    {
        return match ($this) {
            self::Sales => '売上の入金',
            self::OtherIn => 'その他の入金',
            self::Purchase => '仕入',
            self::Salary => '給料（全員の合計）',
            self::Lease => 'リース',
            self::OtherOut => 'その他の支払い',
        };
    }

    public function direction(): ScheduleDirection
    {
        return match ($this) {
            self::Sales, self::OtherIn => ScheduleDirection::In,
            self::Purchase, self::Salary, self::Lease, self::OtherOut => ScheduleDirection::Out,
        };
    }

    public function allowsPartner(): bool
    {
        return $this !== self::Salary;
    }

    /**
     * @return list<self>
     */
    public static function forDirection(ScheduleDirection $direction): array
    {
        return array_values(array_filter(self::cases(), fn (self $category): bool => $category->direction() === $direction));
    }
}
