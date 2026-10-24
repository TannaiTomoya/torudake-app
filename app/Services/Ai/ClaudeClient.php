<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ClaudeClient implements AiClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $url,
        private readonly string $version,
        private readonly int $timeoutSeconds,
    ) {}

    public function complete(AiRequest $request): AiResult
    {
        if ($this->apiKey === '') {
            throw new AiException('ANTHROPIC_API_KEY が設定されていません。');
        }

        $content = [];

        foreach ($request->attachments as $attachment) {
            if (! in_array($attachment['media_type'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'], true)) {
                throw new AiException('AIで扱えない画像形式です。JPEG・PNG・WebPまたはPDFで送ってください。');
            }

            $content[] = [
                'type' => $attachment['media_type'] === 'application/pdf' ? 'document' : 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $attachment['media_type'],
                    'data' => $attachment['data'],
                ],
            ];
        }

        $content[] = [
            'type' => 'text',
            'text' => json_encode($request->input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => $this->version,
            ])
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->post($this->url, [
                    'model' => $this->model,
                    'max_tokens' => $request->maxTokens,
                    'system' => $request->system,
                    'messages' => [
                        ['role' => 'user', 'content' => $content],
                    ],
                    'output_config' => [
                        'effort' => $request->effort,
                        'format' => [
                            'type' => 'json_schema',
                            'schema' => $request->schema,
                        ],
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new AiException('AIにつながりませんでした（'.$e->getMessage().'）', previous: $e);
        }

        if ($response->failed()) {
            $message = $response->json('error.message');

            throw new AiException('AIがエラーを返しました（HTTP '.$response->status().'）'.(is_string($message) ? '：'.$message : ''));
        }

        $text = '';
        $blocks = $response->json('content');

        if (is_array($blocks)) {
            foreach ($blocks as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                    $text .= $block['text'];
                }
            }
        }

        $decoded = json_decode($text, true);

        $inputTokens = $response->json('usage.input_tokens');
        $outputTokens = $response->json('usage.output_tokens');

        return new AiResult(
            output: is_array($decoded) ? $decoded : null,
            inputTokens: is_int($inputTokens) ? $inputTokens : 0,
            outputTokens: is_int($outputTokens) ? $outputTokens : 0,
        );
    }

    public function driverName(): string
    {
        return 'claude';
    }

    public function modelName(): string
    {
        return $this->model;
    }
}
