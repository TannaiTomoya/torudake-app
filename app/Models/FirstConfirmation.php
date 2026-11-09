<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * 仕訳の初回確定の時点の記録（#32）。作ったあとは書き換えも削除もしない。
 * 測っているのは「下書きに手を入れたか」という行動で、AI の答えが会計として正しかったかではない。
 *
 * @property string $id
 * @property string $company_id
 * @property string $journal_entry_id
 * @property string|null $source_record_id
 * @property string $kind
 * @property string $evaluation_class
 * @property string|null $ai_run_id
 * @property string|null $driver
 * @property string|null $prompt_version
 * @property bool $required_complete
 * @property list<string> $changed_fields
 * @property list<string> $filled_fields
 * @property bool $description_changed
 * @property array<string, string> $draft_origin
 * @property array<string, string> $reading_methods
 * @property array{shown: int, concerning: int, decided: int} $duplicate
 * @property array<string, mixed>|null $recommendation 履歴からの候補の扱い（#54）
 * @property string|null $reason
 * @property int $first_confirmed_by
 * @property Carbon $first_confirmed_at
 */
class FirstConfirmation extends Model
{
    use HasUuids;

    /** AI が下書きを作った */
    public const KIND_AI = 'ai';

    /** AI の下書きがなく、人が最初から入れた */
    public const KIND_MANUAL = 'manual';

    /** 実際の AI（claude）で、デモ会社ではない */
    public const CLASS_REAL = 'real';

    /** fake・replay・デモ会社。実際の数字とは混ぜない */
    public const CLASS_DEMO = 'demo';

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('初回確定の記録は書き換えられません。');
        });

        static::deleting(function (): void {
            throw new LogicException('初回確定の記録は削除できません。');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required_complete' => 'boolean',
            'changed_fields' => 'array',
            'filled_fields' => 'array',
            'description_changed' => 'boolean',
            'draft_origin' => 'array',
            'reading_methods' => 'array',
            'duplicate' => 'array',
            'recommendation' => 'array',
            'first_confirmed_at' => 'datetime',
        ];
    }

    /**
     * 下書きを手を入れずに確定した（必須項目が揃い、AI の値を変えず、記載のなかった欄も人が埋めていない）。摘要は含めない。
     */
    public function isUnchanged(): bool
    {
        return $this->kind === self::KIND_AI
            && $this->required_complete
            && $this->changed_fields === []
            && $this->filled_fields === [];
    }
}
