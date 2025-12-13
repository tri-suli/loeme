<?php

namespace Database\Factories;

use App\Enums\Crypto;
use App\Models\Order;
use App\Models\Trade;
use Illuminate\Database\Eloquent\Factories\Factory;

class TradeFactory extends Factory
{
    protected $model = Trade::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trade_uid'     => $this->faker->uuid,
            'buy_order_id'  => Order::factory(),
            'sell_order_id' => Order::factory(),
            'symbol'        => Crypto::BTC->value,
            'price'         => $this->faker->randomFloat(2, 1, 10000),
            'amount'        => $this->faker->randomFloat(2, 1, 10000),
            'executed_at'   => now(),
        ];
    }
}
