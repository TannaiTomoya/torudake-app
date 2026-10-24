<?php

namespace App\Services\Ai;

use App\Enums\AiTask;
use App\Models\AiRun;
use App\Models\Company;
use Illuminate\Database\Eloquent\Model;

/**
 * AI を1回呼び、形を確かめ、ai_runs に残す。形が違えばやり直しは1回まで。
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
    ): AiRun {
        $run = new AiRun;
        $run->forceFill([
            'company_id' => $company->id,
            'task' => $request->task,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject !== null ? (string) $subject->getKey() : null,
            'model' => $this->client->modelName(),
            'prompt_version' => $promptVersion,
            'driver' => $this->client->driverName(),
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
