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
