<?php

namespace App\Services;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Events\OrderMatched;
use App\Models\Asset;
use App\Models\Order;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MatchingService
{
    /**
     * Commission rate as a decimal string (1.5% = 0.015)
     */
    private const COMMISSION_RATE = '0.015';

    /**
     * Platform account identifier. Fees are credited to this user (USD balance).
     */
    private const PLATFORM_EMAIL = 'platform@loeme.local';

    /**
     * Try to match the provided newly-created open order with the first eligible opposite order.
     * Full match only; no partial fills.
     *
     * @return array<string, mixed>|null Broadcast payload when matched; otherwise null.
     */
    public function tryMatch(Order $newOrder): ?array
    {
        try {
            $result = DB::transaction(function () use ($newOrder) {
                // Reload and lock the new order to ensure it is still open
                /** @var Order $freshNew */
                $freshNew = Order::query()->whereKey($newOrder->id)->lockForUpdate()->firstOrFail();
                if (! $freshNew->status->isOpen()) {
                    return null; // already matched/cancelled
                }

                $symbol = $freshNew->symbol->value;
                $amount = (string) $freshNew->amount;
                $side = $freshNew->side;

                // Build opposing order query (must be open, same symbol, equal amount, price condition)
                $oppositeSide = $side->isBuying() ? OrderSide::SELL : OrderSide::BUY;

                $oppositeQuery = Order::query()
                    ->where('symbol', $symbol)
                    ->where('side', $oppositeSide)
                    ->where('status', OrderStatus::OPEN)
                    ->where('amount', $amount);

                if ($side->isBuying()) {
                    // BUY matches first SELL with price <= buy.price
                    $oppositeQuery->where('price', '<=', (string) $freshNew->price)
                        ->orderBy('price')
                        ->orderBy('created_at')
                        ->orderBy('id');
                } else {
                    // SELL matches first BUY with price >= sell.price
                    $oppositeQuery->where('price', '>=', (string) $freshNew->price)
                        ->orderBy('price', 'desc')
                        ->orderBy('created_at')
                        ->orderBy('id');
                }

                /** @var Order|null $counter */
                $counter = $oppositeQuery->lockForUpdate()->first();
                if (! $counter) {
                    return null; // No eligible opposite order
                }

                // Lock users involved
                /** @var User $buyer */
                /** @var User $seller */
                if ($side->isBuying()) {
                    $buyer = User::query()->whereKey($freshNew->user_id)->lockForUpdate()->firstOrFail();
                    $seller = User::query()->whereKey($counter->user_id)->lockForUpdate()->firstOrFail();
                } else {
                    $buyer = User::query()->whereKey($counter->user_id)->lockForUpdate()->firstOrFail();
                    $seller = User::query()->whereKey($freshNew->user_id)->lockForUpdate()->firstOrFail();
                }

                // Determine execution price: price of the resting order (counter)
                $executionPrice = (string) $counter->price;
                $grossUsd = bcmul($executionPrice, $amount, 18);
                $commission = $this->calcCommission($grossUsd);

                // Ensure the seller has the asset locked; lock asset rows
                $sellerAsset = Asset::query()
                    ->where('user_id', $seller->id)
                    ->where('symbol', $symbol)
                    ->lockForUpdate()
                    ->first();
                if (! $sellerAsset) {
                    // Should not happen if order was allowed to open; treat as failure
                    throw new \RuntimeException('Seller asset not found');
                }

                // Buyer asset row (may not exist yet)
                $buyerAsset = Asset::query()
                    ->where('user_id', $buyer->id)
                    ->where('symbol', $symbol)
                    ->lockForUpdate()
                    ->first();
                if (! $buyerAsset) {
                    $buyerAsset = new Asset([
                        'user_id'       => $buyer->id,
                        'symbol'        => $symbol,
                        'amount'        => '0',
                        'locked_amount' => '0',
                    ]);
                    $buyerAsset->save();
                    // Lock freshly created row too
                    $buyerAsset = Asset::query()
                        ->where('user_id', $buyer->id)
                        ->where('symbol', $symbol)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                // Update orders to filled
                $freshNew->status = OrderStatus::FILLED;
                $freshNew->remaining = '0';
                $freshNew->save();

                $counter->status = OrderStatus::FILLED;
                $counter->remaining = '0';
                $counter->save();

                // Move crypto: deduct seller locked_amount, not from amount; credit buyer amount
                $sellerLocked = (string) $sellerAsset->locked_amount;
                // Safety: ensure locked amount >= trade amount
                if (bccomp($sellerLocked, $amount, 18) < 0) {
                    throw new \RuntimeException('Seller locked amount insufficient for match');
                }
                $sellerAsset->locked_amount = bcsub($sellerLocked, $amount, 18);
                $sellerAsset->save();

                $buyerAsset->amount = bcadd((string) $buyerAsset->amount, $amount, 18);
                $buyerAsset->save();

                // USD: Buyer already had funds deducted when placing BUY order
                // Identify which order is buyer's original order to compute refund if needed
                if ($side->isBuying()) {
                    // Buyer is the new order. Buyer pays commission (USD) from reserved funds.
                    $reservedCost = bcmul((string) $freshNew->price, $amount, 18);
                    $reservedFee = $this->calcCommission($reservedCost);
                    $reservedTotal = bcadd($reservedCost, $reservedFee, 18);
                    // delta = (reserved cost+fee at order) - (executed gross+fee at execution)
                    $delta = bcsub($reservedTotal, bcadd($grossUsd, $commission, 18), 18);
                    if (bccomp($delta, '0', 18) > 0) {
                        // Refund the difference (e.g., price improvement and/or lower fee at execution)
                        $buyer->balance = $this->formatUsd(bcadd((string) $buyer->balance, $delta, 2));
                        $buyer->save();
                    }
                    // If delta <= 0, do nothing; never extra‑debit beyond reservation
                } else {
                    // Buyer is the resting order ($counter). Buyer pays commission (USD) from its original reservation.
                    $reservedCost = bcmul((string) $counter->price, $amount, 18);
                    $reservedFee = $this->calcCommission($reservedCost);
                    $reservedTotal = bcadd($reservedCost, $reservedFee, 18);
                    $delta = bcsub($reservedTotal, bcadd($grossUsd, $commission, 18), 18);
                    if (bccomp($delta, '0', 18) > 0) {
                        // Refund unlikely here (usually exec at resting price), but handle generically
                        $buyer->balance = $this->formatUsd(bcadd((string) $buyer->balance, $delta, 2));
                        $buyer->save();
                    }
                    // If delta <= 0, do nothing; never extra‑debit beyond reservation
                }

                // Credit platform fee wallet (USD) and seller proceeds.
                // Lock or create the platform account inside the same transaction for conservation.
                $platform = User::query()
                    ->where('email', self::PLATFORM_EMAIL)
                    ->lockForUpdate()
                    ->first();

                if (! $platform) {
                    $platform = new User([
                        'name'     => 'Platform',
                        'email'    => self::PLATFORM_EMAIL,
                        'password' => '',
                        'balance'  => '0.00',
                    ]);
                    $platform->save();
                    // Re-lock the freshly created row
                    $platform = User::query()
                        ->where('email', self::PLATFORM_EMAIL)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                // Credit platform with the commission in USD (quote currency)
                $platform->balance = $this->formatUsd(bcadd((string) $platform->balance, $commission, 2));
                $platform->save();

                // Buyer pays fee: seller receives full gross amount in USD
                $seller->balance = $this->formatUsd(bcadd((string) $seller->balance, $grossUsd, 2));
                $seller->save();

                // Persist trade (idempotent) and prepare broadcast payload
                $buyOrderId = $side->isBuying() ? $freshNew->id : $counter->id;
                $sellOrderId = $side->isBuying() ? $counter->id : $freshNew->id;
                $tradeUid = hash('sha256', implode('|', [
                    $symbol,
                    (string) $buyOrderId,
                    (string) $sellOrderId,
                    $executionPrice,
                    $amount,
                ]));

                $trade = Trade::query()->firstOrCreate(
                    [
                        'trade_uid' => $tradeUid,
                    ],
                    [
                        'buy_order_id'  => $buyOrderId,
                        'sell_order_id' => $sellOrderId,
                        'symbol'        => $symbol,
                        'price'         => $executionPrice,
                        'amount'        => $amount,
                        'executed_at'   => now(),
                        // Fee fields persisted for accounting and reconciliation
                        'fee_amount'   => $commission,
                        'fee_currency' => 'USD',
                        'fee_payer'    => 'buyer',
                    ]
                );

                // In the unlikely case the trade existed (idempotency), ensure fee fields are populated
                if (
                    ($trade->fee_amount ?? null) !== $commission ||
                    ($trade->fee_currency ?? null) !== 'USD' ||
                    ($trade->fee_payer ?? null) !== 'buyer'
                ) {
                    $trade->fee_amount = $commission;
                    $trade->fee_currency = 'USD';
                    $trade->fee_payer = 'buyer';
                    $trade->save();
                }

                $payload = [
                    'trade_id'      => $trade->trade_uid,
                    'symbol'        => $symbol,
                    'price'         => $executionPrice,
                    'amount'        => $amount,
                    'buy_order_id'  => $buyOrderId,
                    'sell_order_id' => $sellOrderId,
                    'buyer_id'      => $buyer->id,
                    'seller_id'     => $seller->id,
                    'commission'    => $commission,
                    'buyer'         => [
                        'balance' => (string) $buyer->balance,
                        'asset'   => [
                            'symbol'        => $symbol,
                            'amount'        => (string) $buyerAsset->amount,
                            'locked_amount' => (string) $buyerAsset->locked_amount,
                        ],
                        'orders' => [
                            'buy' => [
                                'id'     => $buyOrderId,
                                'status' => OrderStatus::FILLED->value,
                            ],
                        ],
                    ],
                    'seller' => [
                        'balance' => (string) $seller->balance,
                        'asset'   => [
                            'symbol'        => $symbol,
                            'amount'        => (string) $sellerAsset->amount,
                            'locked_amount' => (string) $sellerAsset->locked_amount,
                        ],
                        'orders' => [
                            'sell' => [
                                'id'     => $sellOrderId,
                                'status' => OrderStatus::FILLED->value,
                            ],
                        ],
                    ],
                ];

                return $payload;
            }, 3);

            if ($result) {
                // Broadcast once per successful match
                event(new OrderMatched($result));
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error('Order matching failed', [
                'order_id' => $newOrder->id,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function calcCommission(string $grossUsd): string
    {
        // commission = rate * gross, keep 2 decimals for USD rounding down
        $raw = bcmul($grossUsd, self::COMMISSION_RATE, 18);

        return $this->formatUsd($raw);
    }

    private function formatUsd(string $value): string
    {
        // Normalize to 2 decimals, rounding down
        if (str_contains($value, '.')) {
            [$w, $f] = explode('.', $value, 2);
            $trimmed = rtrim($f, '0');
            $take = substr($trimmed, 0, 2);

            return $w . '.' . str_pad($take, 2, '0');
        }

        return $value . '.00';
    }
}
