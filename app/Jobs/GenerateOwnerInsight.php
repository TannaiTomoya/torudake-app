<?php

namespace App\Jobs;

use App\Enums\InsightKind;
use App\Models\Company;
use App\Models\OwnerInsight;
use App\Services\Ai\AiException;
use App\Services\Ai\AiTaskRunner;
use App\Services\Ai\Tasks\Forecast;
use App\Services\Ai\Tasks\OwnerBrief;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 社長へのひとこと・来月の見込みを AI で作り直す。頼んだときの入力とハッシュをそのまま使う。
 */
class GenerateOwnerInsight implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public string $companyId,
        public string $kind,
        public string $hash,
        public array $input,
    ) {}

    public function handle(AiTaskRunner $runner, OwnerBrief $brief, Forecast $forecast): void
    {
        $company = Company::query()->find($this->companyId);
        $kind = InsightKind::tryFrom($this->kind);

        if ($company === null || $kind === null) {
            return;
        }

        try {
            $runner->assertWithinDailyLimit($company);

            $outcome = match ($kind) {
                InsightKind::Brief => $brief->run($company, $this->input),
                InsightKind::Forecast => $forecast->run($company, $this->input),
            };
        } catch (Throwable $e) {
            $this->finish($company, $kind, fn (OwnerInsight $insight) => $insight->forceFill([
                'error' => $e instanceof AiException ? $e->getMessage() : '作り直せませんでした。',
            ]));

            if (! $e instanceof AiException) {
                report($e);
            }

            return;
        }

        $this->finish($company, $kind, fn (OwnerInsight $insight) => $insight->forceFill([
            'input_hash' => $this->hash,
            'output' => $outcome->output,
            'ai_run_id' => $outcome->run->id,
            'generated_at' => now(),
            'error' => null,
        ]));
    }

    /**
     * @param  callable(OwnerInsight): OwnerInsight  $apply
     */
    private function finish(Company $company, InsightKind $kind, callable $apply): void
    {
        DB::transaction(function () use ($company, $kind, $apply): void {
            $insight = $company->ownerInsights()->where('kind', $kind)->lockForUpdate()->first();

            if ($insight === null) {
                return;
            }

            $apply($insight);

            if ($insight->pending_hash === $this->hash) {
                // 失敗のときは pending_at を残し、5分は頼み直さない（開くたびに AI を呼ばないため）
                $insight->forceFill(['pending_hash' => null, 'pending_at' => $insight->error === null ? null : $insight->pending_at]);
            }

            $insight->save();
        });
    }
}
