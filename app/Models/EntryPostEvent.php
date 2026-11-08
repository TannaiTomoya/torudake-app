<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * 初回確定のあとの出来事（#32）。社長が戻した（returned）・経理が直して再確定した（reconfirmed）。書き換えも削除もしない。
 *
 * @property string $id
 * @property string $company_id
 * @property string $journal_entry_id
 * @property string $kind
 * @property list<string> $changed_fields
 * @property int $actor_id
 * @property Carbon $occurred_at
 */
class EntryPostEvent extends Model
{
    use HasUuids;

    public const KIND_RETURNED = 'returned';

    public const KIND_RECONFIRMED = 'reconfirmed';

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('確定後の出来事の記録は書き換えられません。');
        });

        static::deleting(function (): void {
            throw new LogicException('確定後の出来事の記録は削除できません。');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changed_fields' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
