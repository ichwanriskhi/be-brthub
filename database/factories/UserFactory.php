<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone_number' => fake()->unique()->phoneNumber(),
            'address' => fake()->address(),
            'auth_service_uuid' => Str::uuid(),
            'is_active' => true,
            'source' => 'local',
            'last_synced_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function employee(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => 'auth_service',
        ]);
    }
}
