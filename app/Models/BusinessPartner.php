<?php

namespace App\Models;

use App\Services\CashScheduleService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 取引先（#67）。本当の名前を持つのはこの表の name だけ。
 * 監査記録・分析用の書き出し・AI への入力・ログには名前を出さない。個人のお客さんは登録しない。
 * 足す・名前を直す・使わなくするのは {@see CashScheduleService} だけ。
 *
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BusinessPartner extends Model
{
    use HasUuids;

    /** 配列・JSON にするときは名前を出さない（うっかり監査記録やログに入れないため） */
    protected $hidden = ['name'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }
}
