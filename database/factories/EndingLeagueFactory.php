<?php

namespace Database\Factories;

use App\Models\EndingLeague;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EndingLeague>
 */
class EndingLeagueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'league' => fake()->unique()->words(2, true), 'is_local' => 'Local', 'is_active' => 'Active',
        ];
    }
}
