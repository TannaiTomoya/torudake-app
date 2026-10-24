<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'fiscal_year_end_month' => 7,
            'bookkeeping_start_date' => '2026-08-01',
            'is_demo' => false,
            'concern_amount_yen' => 30000,
        ];
    }

    public function demo(): static
    {
        return $this->state(fn (): array => [
            'is_demo' => true,
        ]);
    }
}
