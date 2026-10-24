<?php

namespace App\Services\Ai;

use App\Enums\AiTask;

final readonly class AiRequest
{
    /**
     * @param  array<string, mixed>  $input  プロンプトに入れて ai_runs.input にも残す値。写真は入れない
     * @param  list<array{media_type: string, data: string}>  $attachments  base64 の写真・PDF（記録しない）
     * @param  array<string, mixed>  $schema  出力の JSON スキーマ
     */
    public function __construct(
        public AiTask $task,
        public string $system,
        public array $input,
        public array $schema,
        public string $effort = 'low',
        public array $attachments = [],
        public int $maxTokens = 4096,
    ) {}
}
