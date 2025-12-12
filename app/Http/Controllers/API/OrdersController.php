<?php

namespace App\Http\Controllers\API;

use App\Enums\Crypto;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Events\OrderCancelled;
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

    /**
     * POST /api/orders/{id}/cancel – Cancel an open order with transactional safety and idempotency.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $result = DB::transaction(function () use ($user, $id) {
            /** @var Order|null $order */
            $order = Order::query()
                ->where('id', $id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                abort(404);
            }

            // Idempotent: if already filled or cancelled, return current state with no side effects
            if ($order->status->isFilled() || $order->status->isCancelled()) {
                $asset = Asset::query()
                    ->where('user_id', $user->id)
                    ->where('symbol', $order->symbol->value)
                    ->first();

                return [
                    'order'     => $order,
                    'portfolio' => [
                        'balance' => (string) $user->balance,
                        'asset'   => $asset ? [
                            'symbol'        => $order->symbol->value,
                            'amount'        => (string) $asset->amount,
                            'locked_amount' => (string) $asset->locked_amount,
                        ] : null,
                    ],
                    'broadcast' => null,
                ];
            }

            // Open order: release locked funds/assets equivalent to remaining and mark as cancelled
            $symbol = $order->symbol->value;
            $remaining = (string) $order->remaining;
            $side = $order->side;

            $portfolio = [
                'balance' => (string) $user->balance,
                'asset'   => null,
            ];

            if ($side->isBuying()) {
                // Refund reserved USD = price * remaining
                $lockedUser = $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $releaseUsd = bcmul((string) $order->price, $remaining, 18);
                $lockedUser->balance = $this->formatUsd(bcadd((string) $lockedUser->balance, $releaseUsd, 2));
                $lockedUser->save();

                $portfolio['balance'] = (string) $lockedUser->balance;
            } else {
                // Release crypto back from locked_amount
                $asset = Asset::query()
                    ->where('user_id', $user->id)
                    ->where('symbol', $symbol)
                    ->lockForUpdate()
                    ->first();

                if (! $asset) {
                    // Create missing asset entry defensively
                    $asset = new Asset([
                        'user_id'       => $user->id,
                        'symbol'        => $symbol,
                        'amount'        => '0',
                        'locked_amount' => '0',
                    ]);
                    $asset->save();
                    // Re-lock created row
                    $asset = Asset::query()
                        ->where('user_id', $user->id)
                        ->where('symbol', $symbol)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                // Safety: ensure we don't underflow locked_amount
                $toRelease = $remaining;
                if (bccomp((string) $asset->locked_amount, $toRelease, 18) < 0) {
                    $toRelease = (string) $asset->locked_amount;
                }

                $asset->locked_amount = bcsub((string) $asset->locked_amount, $toRelease, 18);
                $asset->amount = bcadd((string) $asset->amount, $toRelease, 18);
                $asset->save();

                $portfolio['asset'] = [
                    'symbol'        => $symbol,
                    'amount'        => (string) $asset->amount,
                    'locked_amount' => (string) $asset->locked_amount,
                ];
            }

            // Update order
            $order->status = OrderStatus::CANCELLED;
            $order->remaining = '0';
            $order->save();

            $broadcast = [
                'order_id'  => $order->id,
                'user_id'   => $user->id,
                'symbol'    => $symbol,
                'side'      => $side->value,
                'price'     => (string) $order->price,
                'status'    => OrderStatus::CANCELLED->value,
                'portfolio' => $portfolio,
            ];

            return [
                'order'     => $order,
                'portfolio' => $portfolio,
                'broadcast' => $broadcast,
            ];
        }, 3);

        // Broadcast cancellation (portfolio and orderbook updates) after commit
        if (! empty($result['broadcast'])) {
            event(new OrderCancelled($result['broadcast']));
        }

        return response()->json([
            'order'     => new OrderResource($result['order']->fresh()),
            'portfolio' => $result['portfolio'],
        ]);
    }

    /**
     * GET /api/my/orders – Current user's open orders and recent history.
     * Query params:
     * - symbol (optional): filter by symbol (btc, eth, ...)
     * - limit (optional): history limit (default 50, max 200)
     */
    public function my(Request $request): JsonResponse
    {
        $user = $request->user();

        $symbol = $request->query('symbol');
        if (is_string($symbol) && $symbol !== '') {
            $symbol = strtolower(trim($symbol));
        } else {
            $symbol = null;
        }

        $limit = (int) ($request->query('limit', 50));
        if ($limit <= 0) {
            $limit = 1;
        }
        if ($limit > 200) {
            $limit = 200;
        }

        $openQuery = Order::query()
            ->where('user_id', $user->id)
            ->where('status', OrderStatus::OPEN)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc');

        $historyQuery = Order::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [OrderStatus::FILLED, OrderStatus::CANCELLED])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit);

        if ($symbol) {
            $openQuery->where('symbol', $symbol);
            $historyQuery->where('symbol', $symbol);
        }

        $open = $openQuery->get();
        $history = $historyQuery->get();

        // Use resources to ensure consistent string decimals and enums
        $openArr = OrderResource::collection($open)->resolve();
        $historyArr = OrderResource::collection($history)->resolve();

        return response()->json([
            'open'    => $openArr,
            'history' => $historyArr,
        ]);
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
