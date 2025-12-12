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
use App\Services\MatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OrdersController extends Controller
{
    /**
     * GET /api/orders – Order book endpoint.
     *
     * Query params:
     * - symbol (required): string crypto symbol (case-insensitive), e.g. BTC, ETH
     * - limit (optional): int number of price levels or raw orders per side (default 100, max 1000)
     * - raw (optional): bool, when true returns individual orders; otherwise aggregated by price level
     *
     * Response (aggregated):
     * {
     *   bids: [{ price: string, amount: string }],
     *   asks: [{ price: string, amount: string }]
     * }
     *
     * Response (raw=true):
     * {
     *   bids: [{ id: int, price: string, remaining: string, created_at: string }],
     *   asks: [{ id: int, price: string, remaining: string, created_at: string }]
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $startedAt = microtime(true);

        // Normalize and validate inputs
        $symbolInput = (string) $request->query('symbol', '');
        $symbol = strtolower(trim($symbolInput));

        if ($symbol === '' || ! in_array($symbol, Crypto::values(), true)) {
            throw ValidationException::withMessages([
                'symbol' => 'Invalid or unsupported symbol.',
            ]);
        }

        $raw = $request->boolean('raw');
        $limit = (int) ($request->query('limit', 100));
        if ($limit <= 0) {
            $limit = 1;
        }
        if ($limit > 1000) {
            $limit = 1000;
        }

        // Consistent read snapshot (no explicit locks)
        $result = DB::transaction(function () use ($symbol, $raw, $limit) {
            if ($raw) {
                // RAW mode: individual open orders ordered by price priority and FIFO by id
                $bids = Order::query()
                    ->select(['id', 'price', 'remaining', 'created_at'])
                    ->where('symbol', $symbol)
                    ->where('status', OrderStatus::OPEN)
                    ->where('side', OrderSide::BUY)
                    ->orderBy('price', 'desc')
                    ->orderBy('created_at', 'asc')
                    ->orderBy('id', 'asc')
                    ->limit($limit)
                    ->get()
                    ->map(static function (Order $o) {
                        return [
                            'id'         => $o->id,
                            'price'      => (string) $o->price,
                            'remaining'  => (string) $o->remaining,
                            'created_at' => $o->created_at?->toIso8601String(),
                        ];
                    })
                    ->values();

                $asks = Order::query()
                    ->select(['id', 'price', 'remaining', 'created_at'])
                    ->where('symbol', $symbol)
                    ->where('status', OrderStatus::OPEN)
                    ->where('side', OrderSide::SELL)
                    ->orderBy('price', 'asc')
                    ->orderBy('created_at', 'asc')
                    ->orderBy('id', 'asc')
                    ->limit($limit)
                    ->get()
                    ->map(static function (Order $o) {
                        return [
                            'id'         => $o->id,
                            'price'      => (string) $o->price,
                            'remaining'  => (string) $o->remaining,
                            'created_at' => $o->created_at?->toIso8601String(),
                        ];
                    })
                    ->values();

                return ['bids' => $bids, 'asks' => $asks];
            }

            // Aggregated mode: group by price level and sum remaining
            $bids = Order::query()
                ->selectRaw('price, SUM(remaining) as amount')
                ->where('symbol', $symbol)
                ->where('status', OrderStatus::OPEN)
                ->where('side', OrderSide::BUY)
                ->groupBy('price')
                ->orderBy('price', 'desc')
                ->limit($limit)
                ->get()
                ->map(static fn ($row) => [
                    'price'  => (string) $row->price,
                    'amount' => (string) $row->amount,
                ])
                ->values();

            $asks = Order::query()
                ->selectRaw('price, SUM(remaining) as amount')
                ->where('symbol', $symbol)
                ->where('status', OrderStatus::OPEN)
                ->where('side', OrderSide::SELL)
                ->groupBy('price')
                ->orderBy('price', 'asc')
                ->limit($limit)
                ->get()
                ->map(static fn ($row) => [
                    'price'  => (string) $row->price,
                    'amount' => (string) $row->amount,
                ])
                ->values();

            return ['bids' => $bids, 'asks' => $asks];
        }, 1);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        Log::info('orders.book.fetch', [
            'symbol'   => $symbol,
            'raw'      => $raw,
            'limit'    => $limit,
            'duration' => $durationMs . 'ms',
            'metric'   => [
                'orders.book.fetch.count'    => 1,
                'orders.book.fetch.duration' => $durationMs,
            ],
        ]);

        return response()->json($result);
    }

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

        // Try to match immediately after order creation (full-match only)
        // Matching is executed in its own transaction and will broadcast after commit
        app(MatchingService::class)->tryMatch($order);

        return new OrderResource($order->fresh());
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
