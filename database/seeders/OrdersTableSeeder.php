<?php

namespace Database\Seeders;

use App\Enums\Crypto;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Models\Asset;
use App\Models\Order;
use App\Models\User;
use App\Services\MatchingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrdersTableSeeder extends Seeder
{
    /**
     * Seed a pair of opposing orders that will immediately match, ensuring the
     * OrderMatched event is dispatched during seeding. Also prepares correct
     * balances and locked amounts consistent with order placement rules.
     */
    public function run(): void
    {
        /** @var User|null $buyer */
        $buyer = User::query()->where('email', 'user1@example.com')->first();
        /** @var User|null $seller */
        $seller = User::query()->where('email', 'user2@example.com')->first();

        if (! $buyer || ! $seller) {
            return; // Users not present; rely on UsersTableSeeder
        }

        $symbol = Crypto::BTC->value;
        $amount = '0.100000000000000000'; // 0.1 BTC
        $price = '60000.00';             // USD/BTC
        $cost = bcmul($price, $amount, 18); // 6000.00

        DB::transaction(function () use ($buyer, $seller, $symbol, $amount, $price, $cost) {
            // BUYER: reserve USD by deducting balance (mimic OrdersController)
            /** @var User $lockedBuyer */
            $lockedBuyer = User::query()->whereKey($buyer->id)->lockForUpdate()->firstOrFail();
            $lockedBuyer->balance = $this->formatUsd(bcsub((string) $lockedBuyer->balance, $cost, 18));
            $lockedBuyer->save();

            // Create BUY order (OPEN)
            /** @var Order $buyOrder */
            $buyOrder = Order::create([
                'user_id'   => $lockedBuyer->id,
                'symbol'    => $symbol,
                'side'      => OrderSide::BUY,
                'price'     => $price,
                'amount'    => $amount,
                'remaining' => $amount,
                'status'    => OrderStatus::OPEN,
            ]);

            // SELLER: move asset from amount to locked_amount
            /** @var Asset $sellerAsset */
            $sellerAsset = Asset::query()
                ->where('user_id', $seller->id)
                ->where('symbol', $symbol)
                ->lockForUpdate()
                ->first();

            if (! $sellerAsset) {
                // If absent (shouldn't happen if AssetsTableSeeder ran), create it with sufficient amount
                $sellerAsset = Asset::create([
                    'user_id'       => $seller->id,
                    'symbol'        => $symbol,
                    'amount'        => '1.000000000000000000',
                    'locked_amount' => '0',
                ]);
                // Re-lock after creation
                $sellerAsset = Asset::query()->whereKey($sellerAsset->id)->lockForUpdate()->firstOrFail();
            }

            // Transfer to locked
            $sellerAsset->amount = bcsub((string) $sellerAsset->amount, $amount, 18);
            $sellerAsset->locked_amount = bcadd((string) $sellerAsset->locked_amount, $amount, 18);
            $sellerAsset->save();

            // Create SELL order (OPEN) at same price and amount so it will match fully
            /** @var Order $sellOrder */
            $sellOrder = Order::create([
                'user_id'   => $seller->id,
                'symbol'    => $symbol,
                'side'      => OrderSide::SELL,
                'price'     => $price,
                'amount'    => $amount,
                'remaining' => $amount,
                'status'    => OrderStatus::OPEN,
            ]);

            // Trigger matching outside of this transaction for clarity using the service
        });

        // Perform matching and broadcast the event
        /** @var Order|null $freshBuy */
        $freshBuy = Order::query()
            ->where('user_id', $buyer->id)
            ->where('symbol', $symbol)
            ->where('side', OrderSide::BUY)
            ->latest('id')
            ->first();

        if ($freshBuy) {
            app(MatchingService::class)->tryMatch($freshBuy);
        }
    }

    private function formatUsd(string $value): string
    {
        // Normalize to 2 decimals, rounding down without floats
        if (str_contains($value, '.')) {
            [$w, $f] = explode('.', $value, 2);
            $trimmed = rtrim($f, '0');
            $take = substr($trimmed, 0, 2);

            return $w . '.' . str_pad($take, 2, '0');
        }

        return $value . '.00';
    }
}
