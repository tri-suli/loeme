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
});
