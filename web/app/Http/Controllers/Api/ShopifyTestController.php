<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ShopifyTestController extends Controller
{
    /**
     * List orders with payment status (hardcoded for now; replace with Shopify API later).
     */
    public function index(): JsonResponse
    {
        $orders = [
            ['name' => '#1001', 'payment_status' => 'paid'],
            ['name' => '#1002', 'payment_status' => 'pending'],
            ['name' => '#1003', 'payment_status' => 'refunded'],
        ];

        return response()->json($orders);
    }
}
