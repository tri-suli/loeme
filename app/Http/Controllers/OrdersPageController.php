<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class OrdersPageController extends Controller
{
    public function __invoke()
    {
        return Inertia::render('Orders');
    }
}
