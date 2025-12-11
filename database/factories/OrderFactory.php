<?php

namespace Database\Factories;

use App\Enums\Crypto;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected $model = Order::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id'   => User::factory(),
            'symbol'    => $this->faker->randomElement(Crypto::values()),
            'side'      => $this->faker->randomElement(['BUY', 'SELL']),
            'price'     => $this->faker->randomFloat(2, 1, 10000),
            'amount'    => $this->faker->randomFloat(2, 1, 10000),
            'remaining' => $this->faker->randomFloat(2, 1, 10000),
        ];
    }
}
