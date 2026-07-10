<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        return [
            'title'            => fake()->sentence(3),
            'description'      => fake()->paragraph(),
            'start_at_utc'     => now()->addDays(30),
            'end_at_utc'       => now()->addDays(30)->addHours(4),
            'display_timezone' => 'Asia/Kuala_Lumpur',
            'status'           => EventStatus::Draft->value,
        ];
    }

    public function published(): static
    {
        return $this->state([
            'status'       => EventStatus::Published->value,
            'published_at' => now(),
        ]);
    }
}
