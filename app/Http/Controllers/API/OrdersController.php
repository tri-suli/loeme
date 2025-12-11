<?php

namespace App\Http\Controllers\API;

use App\Enums\Crypto;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Asset;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrdersController extends Controller
{
    public function store(StoreOrderRequest $request): OrderResource
    {
        $user = $request->user();
        $data = $request->validated();

        // All monetary math as strings; use bcmath for precision
        $price = (string) $data['price'];
        $amount = (string) $data['amount'];
        $cost = bcmul($price, $amount, 18);

        /** @var Order $order */
        $order = DB::transaction(function () use ($user, $data, $price, $amount, $cost) {
            $side = OrderSide::tryFrom($data['side']);
            $symbol = Crypto::tryFrom($data['symbol'])->value;

            if ($side && $side->isBuying()) {
                // BUY: Lock user USD balance row and deduct cost
                $lockedUser = $user->newQuery()->whereKey($user->id)->lockForUpdate()->first();
                $currentBalance = (string) $lockedUser->balance;
                if (bccomp($currentBalance, $cost, 18) < 0) {
                    throw ValidationException::withMessages([
                        'amount' => 'Insufficient USD balance to place this buy order.',
                    ]);
                }
                $newBalance = bcsub($currentBalance, $cost, 18);
                $lockedUser->balance = $this->formatUsd($newBalance);
                $lockedUser->save();

                return Order::create([
                    'user_id'   => $lockedUser->id,
                    'symbol'    => $symbol,
                    'side'      => OrderSide::BUY,
                    'price'     => $price,
                    'amount'    => $amount,
                    'remaining' => $amount,
                    'status'    => OrderStatus::OPEN,
                ]);
            }

            // SELL: lock user's asset row for the symbol and move amount to locked_amount
            $asset = Asset::query()
                ->where('user_id', $user->id)
                ->where('symbol', $symbol)
                ->lockForUpdate()
                ->first();

            $available = $asset ? (string) $asset->amount : '0';
            if (bccomp($available, $amount, 18) < 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Insufficient asset balance to place this sell order.',
                ]);
            }

            // Update amounts atomically
            $asset->amount = bcsub($available, $amount, 18);
            $asset->locked_amount = bcadd((string) $asset->locked_amount, $amount, 18);
            $asset->save();

            return Order::create([
                'user_id'   => $user->id,
                'symbol'    => $symbol,
                'side'      => OrderSide::SELL,
                'price'     => $price,
                'amount'    => $amount,
                'remaining' => $amount,
                'status'    => OrderStatus::OPEN,
            ]);
        });

        return new OrderResource($order);
    }

    private function formatUsd(string $value): string
    {
        // Ensure we keep at least 2 decimals for USD storage
        if (str_contains($value, '.')) {
            [$w, $f] = explode('.', $value, 2);

            return $w . '.' . str_pad(substr(rtrim($f, '0'), 0, 2), 2, '0');
        }

        return $value . '.00';
    }
}
