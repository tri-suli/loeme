<?php

namespace App\Http\Controllers\API;

use App\Enums\Crypto;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\Order\OrderResource;
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
            // Lock the user row for balance check/update
            $lockedUser = $user->newQuery()->whereKey($user->id)->lockForUpdate()->first();

            $currentBalance = (string) $lockedUser->balance;

            // Compare using bccomp (returns -1, 0, 1). If balance < cost -> reject
            if (bccomp($currentBalance, $cost, 18) < 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Insufficient USD balance to place this buy order.',
                ]);
            }

            // Deduct cost from balance
            $newBalance = bcsub($currentBalance, $cost, 18);
            // Persist with 2 decimals for USD balance column
            $lockedUser->balance = $this->formatUsd($newBalance);
            $lockedUser->save();

            return Order::create([
                'user_id'   => $lockedUser->id,
                'symbol'    => Crypto::tryFrom($data['symbol'])->value,
                'side'      => OrderSide::BUY,
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
