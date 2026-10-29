<?php

namespace Database\Factories;

use App\Models\EndingLeague;
use App\Models\EndingTeam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EndingTeam>
 */
class EndingTeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'league_id' => EndingLeague::factory(), 'team_name' => fake()->unique()->company(), 'is_active' => 'Active',
        ];
    }
}
