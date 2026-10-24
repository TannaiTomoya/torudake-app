<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    protected $model = Membership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => User::factory()->withTwoFactor(),
            'role' => Role::Editor,
            'submit_only' => false,
            'disabled_at' => null,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (): array => [
            'role' => Role::Owner,
            'submit_only' => false,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (): array => [
            'role' => Role::Admin,
            'submit_only' => false,
        ]);
    }

    public function approver(): static
    {
        return $this->state(fn (): array => [
            'role' => Role::Approver,
            'submit_only' => false,
        ]);
    }

    public function editor(): static
    {
        return $this->state(fn (): array => [
            'role' => Role::Editor,
            'submit_only' => false,
        ]);
    }

    public function viewer(): static
    {
        return $this->state(fn (): array => [
            'role' => Role::Viewer,
            'submit_only' => false,
        ]);
    }

    public function submitOnly(): static
    {
        return $this->state(fn (): array => [
            'role' => Role::Editor,
            'submit_only' => true,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => [
            'disabled_at' => now(),
        ]);
    }
}
