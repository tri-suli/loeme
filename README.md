# Limit‑Order Exchange Mini Engine (Laravel + Vue 3)

## Overview
A compact limit‑order exchange engine with a Laravel API and a Vue 3 SPA. It supports price‑time priority matching, precise decimal accounting, row‑level locking for concurrency safety, and real‑time updates via Pusher‑compatible websockets. Authentication uses Laravel Sanctum. All money/amount values are represented as strings/decimals to avoid floating‑point errors.

## Tech stack
- Backend: Laravel 12.x (laravel/framework ^12.0), PHP 8.3+ (bcmath, intl), Redis (queues/cache), MySQL 8+ or PostgreSQL 14+
- Frontend: Vue 3.5.x (^3.5.25) with Vite (Composition API, TypeScript), Axios, Laravel Echo
- Realtime: Pusher SaaS or compatible server (Laravel WebSockets / Reverb)
- Testing: PHPUnit 11.x (^11.5.3), Vitest + Vue Test Utils

## Main features
- Limit orders: buy/sell with price‑time priority and FIFO within price level
- Matching engine with transactional locking and precise decimals (no floats)
- Balance/asset locking on order placement; atomic release on cancel/fill
- Order book API (aggregated or raw) with fast indexed queries
- Idempotent order placement/cancel via client/server keys
- Real‑time broadcasting of portfolio and order book updates
- Secure private channels (Sanctum) and rate-limited endpoints

## High‑level architecture
- API (Laravel)
  - Controllers expose endpoints for profile, order book, place/cancel orders
  - Jobs queue per symbol handle matching to serialize execution
  - Events (ShouldBroadcast, afterCommit) emit order/portfolio/book updates
  - Repositories/Models encapsulate decimal math with bcmath/BigDecimal
- SPA (Vue 3)
  - Authenticated pages for trading, orders, and portfolio
  - Laravel Echo subscribes to private channels for live updates
- Data stores
  - MySQL/PostgreSQL for primary data
  - Redis for queues/cache/rate limiting

Flow (simplified)

```
Client → POST /api/orders ──▶ DB txn (lock balances/assets) ──▶ enqueue OrderPlaced[symbol]
                      ▲                                               │
                      │                                               ▼
          Echo portfolio.{userId} ◀── match loop ── trades/cancels ──▶ Echo orderbook.{symbol}
```

## Database schema (minimum)
- users: standard Laravel users + balance DECIMAL(20,8) UNSIGNED DEFAULT 0
- assets: id, user_id FK, symbol VARCHAR(20), amount DECIMAL(36,18) DEFAULT 0, locked_amount DECIMAL(36,18) DEFAULT 0, UNIQUE(user_id, symbol)
- orders: id, user_id FK, symbol VARCHAR(20), side ENUM('buy','sell'), price DECIMAL(36,18), amount DECIMAL(36,18), remaining DECIMAL(36,18), status TINYINT (1=open,2=filled,3=cancelled), created_at indexed, updated_at
- trades: id, buy_order_id, sell_order_id, symbol, price DECIMAL(36,18), amount DECIMAL(36,18), executed_at

Indexes
- orders: (symbol, status), (symbol, side, price DESC, id ASC), (symbol, side, price ASC, id ASC)
- assets: UNIQUE(user_id, symbol)

Precision
- Use DECIMAL in DB and string‑backed decimals in PHP/JS; never cast to float

## API endpoints

### GET /api/profile
Returns balances and assets for the authenticated user.

Response
```
{
  "usdBalance": "10000.00",
  "assets": [
    {
      "symbol": "BTC",
      "amount": "1.000000000000000000",
      "locked": "0"
    }
  ]
}
```

### GET /api/orders
Returns order book for a symbol.

Query: symbol=BTC|ETH, limit (default 100), raw=true|false

Aggregated example
```
{
  "bids": [
    {
      "price": "68000.000000000000000000",
      "amount": "1.250000000000000000"
    }
  ],
  "asks": [
    {
      "price": "68100.000000000000000000",
      "amount": "0.500000000000000000"
    }
  ]
}
```

### POST /api/orders
Body
```
{ "symbol": "BTC", "side": "buy", "price": "68000", "amount": "0.5" }
```
Returns created order and any immediate trades. Validates funds/assets and decimal scales.

### POST /api/orders/{id}/cancel
Cancels an open order and releases locked funds/assets atomically.

Response (200)
```
{
  "order": {
    "id": 123,
    "status": 3
  },
  "portfolio": {
    "balance": "…",
    "asset": {
      "symbol": "BTC",
      "amount": "…",
      "locked_amount": "…"
    }
  }
}
```

Errors
- 401 Unauthorized, 404 Not Found (ownership), 422 Unprocessable Content (validation)

Notes
- Prices/amounts are strings; return only status=open in the book; indexes ensure performance

## Business rules — Matching engine
- Priority: price‑time (bids best price highest first; asks lowest first; FIFO within same price)
- Transaction model: all balance/asset/order changes occur inside a single DB transaction; rows are locked with SELECT … FOR UPDATE
- Matching loop: fetch opposing orders by price then time; consume amounts until the incoming order is filled or book price is unfavorable
- Concurrency: one in‑process matcher per symbol (queue partition or mutex); Postgres may use FOR UPDATE SKIP LOCKED for concurrent workers if adopted
- Precision: all arithmetic uses bcmath/BigDecimal; rounding mode ROUND_DOWN to the symbol’s scale
- Idempotency: accept Idempotency‑Key on place/cancel; repeated requests within a window return the original result
- Events: broadcast after commit to avoid phantom updates; payloads include only necessary fields

## Trading commissions
- Commission: 1.5% of matched USD notional (must stay per requirements).
- Policy: buyer pays in quote currency (USD) consistently.
  - Calculation: fee = amount × price × 0.015; rounded down to 2 decimals (USD cents).
  - Settlement (atomic in the matching transaction):
    - Buyer: total USD debit equals gross + fee.
    - Seller: credited gross USD proceeds (no fee deducted on seller).
    - Platform: USD balance credited by the fee amount to the platform account (email: platform@loeme.local).
  - Persistence: each Trade row stores fee_amount, fee_currency, and fee_payer.
  - Conservation: buyer USD debit == seller USD credit + platform USD fee credit.

## Real‑time integration
This project uses Laravel Broadcasting with Pusher‑compatible websockets and a Vue 3 SPA using Laravel Echo. Private channels are authorized via Sanctum session auth.

Backend (.env)
- BROADCAST_DRIVER=pusher | reverb | log | null (BROADCAST_CONNECTION supported as fallback)
- Pusher keys/host: PUSHER_APP_ID, PUSHER_APP_KEY, PUSHER_APP_SECRET, PUSHER_APP_CLUSTER
- If self‑hosted: PUSHER_HOST, PUSHER_PORT (e.g., 6001), PUSHER_SCHEME=http|https
- Reverb (first‑party websockets): REVERB_APP_ID, REVERB_APP_KEY, REVERB_APP_SECRET, REVERB_HOST, REVERB_PORT, REVERB_SCHEME
- Queues: QUEUE_CONNECTION=redis|database; events use $afterCommit=true and broadcastQueue="broadcasts"

Channels (routes/channels.php)
- private-user.{id}: per‑user trading updates (OrderMatched, OrderCancelled)
- portfolio.{id}: per‑user portfolio updates
- orderbook.{symbol}: per‑symbol order book updates

Frontend (Vite/Echo)
- Shared bootstrap resources/js/echo.ts creates window.Echo from Vite env vars
- Vite vars: VITE_ECHO_ENABLED, VITE_PUSHER_KEY, VITE_PUSHER_CLUSTER, VITE_PUSHER_HOST, VITE_PUSHER_PORT, VITE_PUSHER_SCHEME, VITE_ECHO_FORCE_TLS
- Private channel auth via /broadcasting/auth with Sanctum session; axios withCredentials=true

Local development
1) Set .env and Vite env vars as above
2) Run: php artisan queue:work; php artisan serve; (optional) php artisan websockets:serve; npm run dev
3) Place matching orders or cancel; observe portfolio/order book updating live without refresh

Security & production notes
- Use TLS (https/wss); match schemes/ports to avoid mixed content
- All channels are private; apply authorization callbacks and throttle auth route
- Emit prices/amounts as strings; avoid sensitive payload data
