<?php

namespace App\Models;

use App\Enums\AiTask;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $company_id
 * @property AiTask $task
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string $model
 * @property string $prompt_version
 * @property string $driver
 * @property array<string, mixed> $input
 * @property array<string, mixed>|null $output
 * @property bool $valid
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $latency_ms
 * @property string|null $error
 * @property Carbon|null $created_at
 */
class AiRun extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task' => AiTask::class,
            'input' => 'array',
            'output' => 'array',
            'valid' => 'boolean',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
