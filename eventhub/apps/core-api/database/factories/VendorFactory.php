<?php

namespace Database\Factories;

use App\Enums\KycStatus;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        return [
            'business_name' => fake()->company(),
            'kyc_status'    => KycStatus::Pending->value,
        ];
    }

    public function verified(): static
    {
        return $this->state([
            'kyc_status'  => KycStatus::Verified->value,
            'verified_at' => now(),
        ]);
    }
}
