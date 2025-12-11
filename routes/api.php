<?php

use App\Http\Controllers\API\ProfileController;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (Router $router) {
    $router->get('profile', ProfileController::class)->name('profile');
});
