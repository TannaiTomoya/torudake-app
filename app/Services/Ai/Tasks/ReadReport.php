<?php

namespace App\Services\Ai\Tasks;

use App\Enums\AiTask;
use App\Models\Company;
use App\Models\ReportImport;
use App\Services\Ai\AiOutcome;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiTaskRunner;
use App\Support\FiscalYear;

/**
 * 帳票（月別売上の三期比較の表）の写真から、月ごとの売上と自信度を読む（7a）。保存は人。
 */
class ReadReport
{
    public const PROMPT_VERSION = 'read_report.v1';

    public function __construct(private readonly AiTaskRunner $runner) {}

    public function run(Company $company, ReportImport $import, string $sha256, string $mime, string $base64): AiOutcome
    {
        $current = FiscalYear::containing(now()->toImmutable(), $company->fiscal_year_end_month);

        return $this->runner->run($company, $import, new AiRequest(
            task: AiTask::ReadReport,
            system: AiTaskRunner::prompt(AiTask::ReadReport),
            input: [
                'today' => now()->toDateString(),
                'fiscal_year_end_month' => $company->fiscal_year_end_month,
                'periods' => [
                    'current' => $current->start->format('Y-m'),
                    'previous' => $current->previous()->start->format('Y-m'),
                    'before' => $current->previous()->previous()->start->format('Y-m'),
                ],
            ],
            schema: self::schema(),
            effort: 'low',
            maxTokens: 4096,
            attachments: [['media_type' => $mime, 'data' => $base64]],
            photoSha256: $sha256,
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
            'required' => ['rows'],
            'properties' => [
                'rows' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['year_month', 'amount_yen', 'confidence'],
                        'properties' => [
                            'year_month' => ['type' => 'string'],
                            'amount_yen' => ['type' => 'integer'],
                            'confidence' => ['type' => 'number'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
