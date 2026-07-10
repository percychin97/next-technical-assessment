<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'email'    => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'role'     => UserRole::Attendee->value,
        ];
    }

    public function vendor(): static
    {
        return $this->state(['role' => UserRole::Vendor->value]);
    }

    public function admin(): static
    {
        return $this->state(['role' => UserRole::Admin->value]);
    }
}
