<?php

namespace Database\Factories;

use App\Models\User;
use App\Services\SecurityAnswer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * 手元の初期データ専用の架空の値（README の「開発環境」と同じ）。本番では使えない。
     */
    public const DEV_PASSWORD = 'password';

    /** 秘密の質問の答え（架空の町） */
    public const DEV_SECURITY_ANSWER = 'テスト町';

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    protected static ?string $securityAnswerHash = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        self::assertNotProduction();

        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make(self::DEV_PASSWORD),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * 秘密の質問の答えを決めてある人。
     */
    public function withSecurityAnswer(): static
    {
        self::assertNotProduction();

        return $this->state(fn (array $attributes) => [
            'security_answer_hash' => static::$securityAnswerHash ??= Hash::make(SecurityAnswer::normalize(self::DEV_SECURITY_ANSWER)),
            'security_answer_set_at' => now(),
        ]);
    }

    private static function assertNotProduction(): void
    {
        if (app()->isProduction()) {
            throw new LogicException('開発用のパスワードと秘密の質問の答えは、本番では使えません。本番の人は torudake:add-member で作ります。');
        }
    }
}
