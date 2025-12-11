<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class TradeController extends Controller
{
    public function __invoke()
    {
        return Inertia::render('Trade');
    }
}
