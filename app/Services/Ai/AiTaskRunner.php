<?php

namespace App\Services\Ai;

use App\Enums\AiTask;
use App\Models\AiRun;
use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Sleep;

/**
 * AI を1回呼び、形を確かめ、ai_runs に残す。形が違えばやり直しは1回まで。
 * 再生モードでは、練習の記録があればそれを同じ間隔で返し、なければ本物を呼ぶ。
 */
class AiTaskRunner
{
    public function __construct(
        private readonly AiClient $client,
        private readonly SchemaValidator $validator,
    ) {}

    public function assertWithinDailyLimit(Company $company): void
    {
        $limit = (int) config('torudake.ai.daily_limit');

        $usedToday = $company->aiRuns()
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($usedToday >= $limit) {
            throw AiException::dailyLimit();
        }
    }

    public function run(Company $company, ?Model $subject, AiRequest $request, string $promptVersion): AiOutcome
    {
        $recorded = $this->recording($company, $request, $promptVersion);

        // 練習後の分類名・口座名の変更や使用停止も、通常の応答と同じスキーマで確かめる。
        if ($recorded !== null && $recorded->output !== null && $this->validator->validate($recorded->output, $request->schema) === []) {
            $startedAt = hrtime(true);
            Sleep::for(min($recorded->latency_ms, (int) config('torudake.ai.timeout_seconds') * 1000))->milliseconds();

            $run = $this->record($company, $subject, $request, $promptVersion, $recorded->output, true, 0, 0, $startedAt, null, 'replay', $recorded->model);

            return new AiOutcome($recorded->output, $run);
        }

        $lastErrors = [];

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $startedAt = hrtime(true);

            try {
                $result = $this->client->complete($request);
            } catch (AiException $e) {
                $this->record($company, $subject, $request, $promptVersion, null, false, 0, 0, $startedAt, $e->getMessage());

                throw $e;
            }

            $lastErrors = $result->output === null
                ? ['JSON として読めません']
                : $this->validator->validate($result->output, $request->schema);

            $valid = $lastErrors === [];

            $run = $this->record(
                $company,
                $subject,
                $request,
                $promptVersion,
                $result->output,
                $valid,
                $result->inputTokens,
                $result->outputTokens,
                $startedAt,
                $valid ? null : implode('／', $lastErrors),
            );

            if ($valid && $result->output !== null) {
                return new AiOutcome($result->output, $run);
            }
        }

        throw AiException::invalidOutput(implode('／', $lastErrors));
    }

    /**
     * 再生モード（AI_DRIVER=replay）で、デモ用の会社に同じ写真・同じプロンプトの版の練習の記録（本物の Claude）があれば返す。
     * 探すのはデモ用の会社どうしだけ。実在の会社では再生しない。
     */
    private function recording(Company $company, AiRequest $request, string $promptVersion): ?AiRun
    {
        if (config('torudake.ai.driver') !== 'replay' || ! $company->is_demo || $request->photoSha256 === null) {
            return null;
        }

        return AiRun::query()
            ->where('photo_sha256', $request->photoSha256)
            ->where('task', $request->task)
            ->where('prompt_version', $promptVersion)
            ->where('driver', 'claude')
            ->where('valid', true)
            ->whereNotNull('output')
            ->whereHas('company', fn ($query) => $query->where('is_demo', true))
            ->latest('created_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>|null  $output
     */
    private function record(
        Company $company,
        ?Model $subject,
        AiRequest $request,
        string $promptVersion,
        ?array $output,
        bool $valid,
        int $inputTokens,
        int $outputTokens,
        int|float $startedAt,
        ?string $error,
        ?string $driver = null,
        ?string $model = null,
    ): AiRun {
        $run = new AiRun;
        $run->forceFill([
            'company_id' => $company->id,
            'task' => $request->task,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject !== null ? (string) $subject->getKey() : null,
            'photo_sha256' => $request->photoSha256,
            'model' => $model ?? $this->client->modelName(),
            'prompt_version' => $promptVersion,
            'driver' => $driver ?? $this->client->driverName(),
            'input' => $request->input,
            'output' => $output,
            'valid' => $valid,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'latency_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'error' => $error,
            'created_at' => now(),
        ])->save();

        return $run;
    }

    public static function prompt(AiTask $task): string
    {
        $path = resource_path("prompts/{$task->value}.md");

        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
