<?php

use App\Http\Controllers\Auth\LoginAttemptController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\OrdersPageController;
use App\Http\Controllers\TradeController;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class)->name('landing');
Route::get('login', LoginController::class)->name('login');
Route::post('login', LoginAttemptController::class)->name('login.attempt');

Route::middleware(['auth'])->group(function (Router $router) {
    $router->get('dashboard', DashboardController::class)->name('dashboard');
    $router->get('trade', TradeController::class)->name('trade');
    $router->get('orders', OrdersPageController::class)->name('orders');
    $router->post('logout', LogoutController::class)->name('logout');
});
