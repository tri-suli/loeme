<?php

use App\Http\Controllers\API\OrdersController;
use App\Http\Controllers\API\ProfileController;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (Router $router) {
    // Order book (GET /api/orders)
    $router->get('orders', [OrdersController::class, 'index'])
        ->middleware('throttle:60,1')
        ->name('orders.index');

    $router->get('profile', ProfileController::class)->name('profile');
    $router->post('orders', [OrdersController::class, 'store'])->name('orders.store');

    // Cancel order
    $router->post('orders/{id}/cancel', [OrdersController::class, 'cancel'])
        ->whereNumber('id')
        ->middleware('throttle:30,1')
        ->name('orders.cancel');

    // Current user's orders (open + recent history)
    $router->get('my/orders', [OrdersController::class, 'my'])
        ->middleware('throttle:120,1')
        ->name('orders.my');
});
