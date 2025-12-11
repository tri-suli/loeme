<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LandingController;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'appName' => config('app.name', 'Laravel'),
    ]);
Route::get('/', LandingController::class)->name('landing');
Route::middleware(['auth'])->group(function (Router $router) {
    $router->get('dashboard', DashboardController::class)->name('dashboard');
});
