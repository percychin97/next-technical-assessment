<?php

namespace Database\Factories;

use App\Models\TicketInventoryPool;
use Illuminate\Database\Eloquent\Factories\Factory;

class TicketInventoryPoolFactory extends Factory
{
    protected $model = TicketInventoryPool::class;

    public function definition(): array
    {
        return [
            'name'           => fake()->words(3, true),
            'capacity_units' => 100,
            'reserved_units' => 0,
            'sold_units'     => 0,
            'version'        => 1,
        ];
    }
}
