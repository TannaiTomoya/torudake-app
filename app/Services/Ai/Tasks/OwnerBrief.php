<?php

namespace App\Services\Ai\Tasks;

use App\Enums\AiTask;
use App\Models\Company;
use App\Services\Ai\AiOutcome;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiTaskRunner;

/**
 * 社長へのひとこと：一番大きな変化を1つ・理由の見立て・確かめるとよい点。渡すのは集計した数字だけ（docs/03 §4.7）。
 */
class OwnerBrief
{
    public const PROMPT_VERSION = 'owner_brief.v2';

    public function __construct(private readonly AiTaskRunner $runner) {}

    /**
     * @param  array<string, mixed>  $input  OwnerInsights::briefInput()
     */
    public function run(Company $company, array $input): AiOutcome
    {
        return $this->runner->run($company, $company, new AiRequest(
            task: AiTask::OwnerBrief,
            system: AiTaskRunner::prompt(AiTask::OwnerBrief),
            input: $input,
            schema: self::schema(),
            effort: 'medium',
            maxTokens: 2048,
        ), self::PROMPT_VERSION);
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['headline', 'reason', 'check', 'busy_alert'],
            'properties' => [
                'headline' => ['type' => 'string'],
                'reason' => ['type' => 'string'],
                'check' => ['type' => 'string'],
                'busy_alert' => ['type' => ['string', 'null']],
            ],
        ];
    }
}
