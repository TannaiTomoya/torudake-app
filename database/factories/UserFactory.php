<?php

namespace Database\Factories;

use App\Models\User;
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

    public const DEV_TWO_FACTOR_SECRET = 'TORUDAKEDEVLOCAL';

    public const DEV_RECOVERY_CODE = 'recovery-code-1';

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

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
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        self::assertNotProduction();

        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt(self::DEV_TWO_FACTOR_SECRET),
            'two_factor_recovery_codes' => encrypt(json_encode([self::DEV_RECOVERY_CODE])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private static function assertNotProduction(): void
    {
        if (app()->isProduction()) {
            throw new LogicException('開発用のパスワードと2段階認証の鍵は、本番では使えません。本番の人は torudake:add-member で作ります。');
        }
    }
}
