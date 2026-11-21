<?php

namespace App\Models;

use App\Services\CategoryRuleService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 分類のルール（店名にキーワードを含む → 分類。#69）。足す・直す・消す・並べ替えは {@see CategoryRuleService} だけ。
 *
 * @property string $id
 * @property string $company_id
 * @property string $keyword
 * @property string $category_id
 * @property int $position
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Category $category
 */
class CategoryRule extends Model
{
    use HasUuids;

    public const KEYWORD_MAX = 60;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
