<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * 決算月から決まる1期（12か月）。決算月が7月なら 8月〜翌7月。
 */
final readonly class FiscalYear
{
    private function __construct(public CarbonImmutable $start) {}

    public static function containing(CarbonInterface $date, int $endMonth): self
    {
        $date = CarbonImmutable::instance($date)->startOfMonth();
        $startMonth = $endMonth % 12 + 1;
        $start = $date->setDate($date->year, $startMonth, 1);

        return new self($start->greaterThan($date) ? $start->subYear() : $start);
    }

    public function previous(): self
    {
        return new self($this->start->subYear());
    }

    public function end(): CarbonImmutable
    {
        return $this->start->addMonths(11)->endOfMonth();
    }

    /**
     * @return list<string> 'Y-m' の12か月
     */
    public function months(): array
    {
        return array_map(fn (int $i): string => $this->start->addMonths($i)->format('Y-m'), range(0, 11));
    }
}
