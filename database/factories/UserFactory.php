<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(), 'email' => fake()->unique()->safeEmail(),
            'mobile_number' => '+639'.fake()->unique()->numerify('#########'),
            'password' => Hash::make('test-password'), 'status' => 'Active',
        ];
    }
}
