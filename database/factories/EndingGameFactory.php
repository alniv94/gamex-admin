<?php

namespace Database\Factories;

use App\Models\EndingGame;
use App\Models\EndingTeam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EndingGame>
 */
class EndingGameFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_a' => EndingTeam::factory(),
            'team_b' => fn (array $attributes) => EndingTeam::factory()->create(['league_id' => EndingTeam::query()->whereKey($attributes['team_a'])->sole()->league_id])->id,
            'schedule' => now()->addDay(), 'betting' => 'Disabled', 'created_at' => now(),
        ];
    }
}
