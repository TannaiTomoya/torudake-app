<?php

namespace App\Services\Ai\Tasks;

use App\Enums\AiTask;
use App\Models\Company;
use App\Services\Ai\AiOutcome;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiTaskRunner;

/**
 * 来月の見込み：売上と経費の中央値と8割の範囲。過去の月の実績・忙しい月の印・3期の平均から（docs/03 §4.7）。
 */
class Forecast
{
    public const PROMPT_VERSION = 'forecast.v1';

    public function __construct(private readonly AiTaskRunner $runner) {}

    /**
     * @param  array<string, mixed>  $input  OwnerInsights::forecastInput()
     */
    public function run(Company $company, array $input): AiOutcome
    {
        return $this->runner->run($company, $company, new AiRequest(
            task: AiTask::Forecast,
            system: AiTaskRunner::prompt(AiTask::Forecast),
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
        $range = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['median_yen', 'low_yen', 'high_yen'],
            'properties' => [
                'median_yen' => ['type' => ['integer', 'null']],
                'low_yen' => ['type' => ['integer', 'null']],
                'high_yen' => ['type' => ['integer', 'null']],
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['month', 'sales', 'expense', 'note'],
            'properties' => [
                'month' => ['type' => 'integer'],
                'sales' => $range,
                'expense' => $range,
                'note' => ['type' => 'string'],
            ],
        ];
    }
}
