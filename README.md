<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Frontend – Live Orders Patching (LME-9)

This project implements live, in-place patching of the Orders list in the SPA using Laravel Echo (Pusher compatible).

- Subscriptions
  - private-user.{userId}: receives OrderMatched and OrderCancelled events relevant to the authenticated user.
  - orderbook.{symbol}: listens for OrderCancelled per selected symbol (filters.symbol) to reflect cancellations promptly.
- Behavior
  - OrderMatched: updates wallet (USD and asset), sets affected order status to filled and moves it from Open to History.
  - OrderCancelled: marks order as cancelled, moves it from Open to History, and applies portfolio release details.
  - Idempotency: duplicate events are ignored (trade_id for matches; order id for cancellations).
  - Sorting/filters: preserved across live updates; lists are re-computed with the current sort and filter state.
  - Cleanup: channels are unsubscribed on component unmount and re-subscribed when the symbol filter changes.

Manual verification (seeded backend)
1. Ensure your .env has valid Pusher (or Laravel WebSockets) config, and you are authenticated in the SPA.
2. In separate terminals:
   - php artisan queue:work
   - php artisan serve
   - If self-hosting websockets: php artisan websockets:serve
3. Open the Orders page (/orders). Keep it open.
4. Place a matching counter-order from another browser session or using the Trade page (/trade).
5. Observe without refresh:
   - Wallet balance/assets update.
   - The matched order moves to History with status FILLED.
6. Cancel an open order (from Orders or via POST /api/orders/{id}/cancel) and observe it moves to History with status CANCELLED and wallet reflects released funds/assets.

## Frontend – Order filtering and toasts/alerts (LME-10)

Implements filter controls for the Orders page with URL query persistence and a centralized toast/alert system with accessibility and de-duplication.

- Filters (Orders page)
  - Controls: Symbol (All/BTC/ETH), Side (All/Buy/Sell), Status (All/Open/Filled/Cancelled), and text Search.
  - Persistence: The current filters are reflected in the page URL query string (symbol, side, status, search) and update reactively without full page reloads.
  - Defaults: If no query is provided, Status defaults to Open. The last-used Symbol is remembered in localStorage.
  - Behavior: Filters are applied client-side to the loaded Open and Recent History lists; changing Symbol triggers a reload of orders and re-subscription to orderbook events for the selected symbol.
  - Accessibility: Native select and input controls with labels; sortable headers are keyboard-activatable (Enter/Space) with visible focus rings. Side coloring: buy=green, sell=red. Status badges are styled and color-coded.

- Toasts/Alerts
  - Centralized store at resources/js/stores/toasts.ts and UI host component at resources/js/components/ToastHost.vue.
  - Usage: The Orders page imports and renders <ToastHost /> so notifications appear globally on that view.
  - Types: success, error, info. Success is used for order placement/cancel; error is used for API and validation/server failures.
  - De-duplication: Toasts use an idempotencyKey when available (e.g., OrderPlaced client key, order id for cancellations, or HTTP method+URL+status); duplicates are ignored.
  - Auto-dismiss: Success/info dismiss after ~5s; error after ~8s. Users can dismiss toasts manually via keyboard or mouse.
  - Accessibility: An ARIA live region announces the newest toast to screen readers. Each toast uses role="status" and provides a Dismiss control with proper focus styles.

- Integration
  - Axios interceptor (resources/js/bootstrap.js) captures HTTP errors and shows an error toast automatically.
  - Laravel Echo events (private-user.{id}, orderbook.{symbol}) show success toasts for OrderPlaced and OrderCancelled, while updates to wallet and orders remain live and idempotent.

Manual verification
1. Build/start the app as in LME-9 and ensure you can access the Orders page.
2. Use the Symbol/Side/Status filters and verify:
   - URL query updates as you change filters, and reload preserves the same view.
   - Status defaults to Open when first visiting without a query.
   - Switching Symbol reloads orders and live subscriptions.
3. Place an order and cancel an order:
   - On success, a green toast appears with concise details (symbol, side, price, amount) and auto-dismisses.
   - On validation/server error, a red toast appears with an actionable message and code; click Details to expand any payload.
4. Verify repeated identical actions with the same idempotency key do not create duplicate toasts.

## API – Order Book (GET /api/orders)

Authenticated endpoint protected by Sanctum and throttled. Returns the current order book for a symbol.

- Method: GET
- Path: /api/orders
- Query params:
    - symbol (required): e.g., BTC, ETH (case-insensitive; must be a supported Crypto enum)
    - limit (optional): number of levels per side; default 100, max 1000
    - raw (optional): when true returns individual open orders; default false (aggregated by price level)

Responses

Aggregated (default)

{
"bids": [
{ "price": "68000.000000000000000000", "amount": "1.250000000000000000" },
{ "price": "67950.000000000000000000", "amount": "0.750000000000000000" }
],
"asks": [
{ "price": "68100.000000000000000000", "amount": "0.500000000000000000" }
]
}

Raw mode (?raw=true)

{
"bids": [
{ "id": 123, "price": "68000.000000000000000000", "remaining": "0.500000000000000000", "created_at": "2025-12-12T18:45:31+00:00" }
],
"asks": [
{ "id": 124, "price": "68100.000000000000000000", "remaining": "0.500000000000000000", "created_at": "2025-12-12T18:46:01+00:00" }
]
}

Errors

- 401 Unauthorized: when not authenticated
- 422 Unprocessable Content: invalid or unsupported symbol

Notes

- Only orders with status=open are included.
- Bids are sorted by price DESC (best first). Asks are sorted by price ASC (best first).
- Amounts and prices are returned as strings to preserve precision; do not cast to float on the client.
- Query performance uses composite indexes on (symbol, status) and (symbol, side, price, id).

## API – Cancel Order (POST /api/orders/{id}/cancel)

Authenticated endpoint protected by Sanctum and throttled. Cancels an open order, releases locked funds/assets atomically, and broadcasts portfolio and orderbook updates.

- Method: POST
- Path: /api/orders/{id}/cancel
- Path params:
    - id (integer): the order ID to cancel. Must belong to the authenticated user.

Responses

200 OK

{
"order": {
"id": 123,
"user_id": 1,
"symbol": "BTC",
"side": "buy",
"price": "68000.000000000000000000",
"amount": "0.500000000000000000",
"remaining": "0",
"status": 3,
"created_at": "2025-12-12T18:45:31+00:00",
"updated_at": "2025-12-12T19:10:00+00:00"
},
"portfolio": {
"balance": "10000.00",
"asset": {
"symbol": "BTC",
"amount": "1.000000000000000000",
"locked_amount": "0.000000000000000000"
}
}
}

Errors

- 401 Unauthorized: when not authenticated
- 404 Not Found: when the order does not exist or does not belong to the user (no existence leak)

Notes

- Idempotent: canceling an already filled or canceled order returns 200 with current state and no side effects.
- All balance changes are done within a single DB transaction with row-level locks.
- Amounts and prices are strings to preserve precision; do not cast to float on the client.
