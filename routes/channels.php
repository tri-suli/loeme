<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Private per-user channel for trading events (OrderMatched, portfolio updates)
Broadcast::channel('private-user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Private portfolio updates per userId
Broadcast::channel('portfolio.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Private orderbook channel per symbol (authorize all authenticated users)
Broadcast::channel('orderbook.{symbol}', function ($user, $symbol) {
    return $user !== null; // any authenticated user can listen to orderbook updates
});
