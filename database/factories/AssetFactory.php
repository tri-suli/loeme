<?php

namespace Database\Factories;

use App\Enums\Crypto;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected $model = Asset::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id'       => User::factory(),
            'symbol'        => $this->faker->randomElement(Crypto::values()),
            'amount'        => $this->faker->randomFloat(2, 1, 10000),
            'locked_amount' => $this->faker->randomFloat(2, 1, 10000),
        ];
    }
}
