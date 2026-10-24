<?php

namespace App\Providers;

use App\Services\Ai\AiClient;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\FakeClaudeClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FakeClaudeClient::class);

        $this->app->bind(AiClient::class, function ($app): AiClient {
            if (in_array(config('torudake.ai.driver'), ['claude', 'replay'], true)) {
                return new ClaudeClient(
                    apiKey: (string) config('services.anthropic.key'),
                    model: (string) config('services.anthropic.model'),
                    url: (string) config('services.anthropic.url'),
                    version: (string) config('services.anthropic.version'),
                    timeoutSeconds: (int) config('torudake.ai.timeout_seconds'),
                );
            }

            return $app->make(FakeClaudeClient::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
