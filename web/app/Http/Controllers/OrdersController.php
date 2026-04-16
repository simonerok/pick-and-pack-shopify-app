<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class OrdersController extends Controller
{
    public function index(): View
    {
        return view('orders');
    }
}
