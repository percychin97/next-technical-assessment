<?php

namespace Database\Factories;

use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

class TicketTypeFactory extends Factory
{
    protected $model = TicketType::class;

    public function definition(): array
    {
        return [
            'code'                        => strtoupper(fake()->lexify('??##')),
            'name'                        => fake()->words(2, true),
            'category'                    => fake()->randomElement(['general_admission', 'vip', 'early_bird', 'group_bundle']),
            'price_minor'                 => fake()->numberBetween(1000, 100000),
            'currency'                    => 'MYR',
            'admission_units_per_purchase' => 1,
            'is_active'                   => true,
        ];
    }

    public function groupBundle(int $units = 4): static
    {
        return $this->state([
            'category'                    => 'group_bundle',
            'admission_units_per_purchase' => $units,
        ]);
    }
}
