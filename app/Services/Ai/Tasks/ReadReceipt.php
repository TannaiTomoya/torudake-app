<?php

namespace App\Services\Ai\Tasks;

use App\Enums\AiTask;
use App\Models\Company;
use App\Models\SourceRecord;
use App\Services\Ai\AiOutcome;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiTaskRunner;

/**
 * L1：写真から全文テキスト・日付・店名・合計（記載なしの判断を含む）・支払い方・税率ごとの金額・登録番号・自信度を読む。
 */
class ReadReceipt
{
    public const PROMPT_VERSION = 'read_receipt.v2';

    public function __construct(private readonly AiTaskRunner $runner) {}

    public function run(Company $company, SourceRecord $source, string $mime, string $base64): AiOutcome
    {
        return $this->runner->run($company, $source, new AiRequest(
            task: AiTask::ReadReceipt,
            system: AiTaskRunner::prompt(AiTask::ReadReceipt),
            input: [
                'note' => $source->note,
                'today' => now()->toDateString(),
            ],
            schema: self::schema(),
            effort: 'low',
            attachments: [['media_type' => $mime, 'data' => $base64]],
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
            'required' => ['full_text', 'date', 'vendor', 'total_yen', 'amount_missing', 'payment_method', 'tax_breakdown', 'registration_number', 'confidence'],
            'properties' => [
                'full_text' => ['type' => 'string'],
                'date' => ['type' => ['string', 'null']],
                'vendor' => ['type' => ['string', 'null']],
                'total_yen' => ['type' => ['integer', 'null']],
                'amount_missing' => ['type' => 'boolean'],
                'payment_method' => ['type' => 'string', 'enum' => ['cash', 'card', 'invoice_later', 'unknown']],
                'tax_breakdown' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['rate', 'amount_yen'],
                        'properties' => [
                            'rate' => ['type' => 'integer'],
                            'amount_yen' => ['type' => 'integer'],
                        ],
                    ],
                ],
                'registration_number' => ['type' => ['string', 'null']],
                'confidence' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['date', 'vendor', 'total'],
                    'properties' => [
                        'date' => ['type' => 'number'],
                        'vendor' => ['type' => 'number'],
                        'total' => ['type' => 'number'],
                    ],
                ],
            ],
        ];
    }
}
