<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ShopifyOrdersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopifyOrdersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            set_time_limit(120);
            $view = $request->query('view');
            if ($view === 'ready-to-pack') {
                $data = ShopifyOrdersService::fetchOrdersReadyToPack();
            } elseif ($view === 'ready-for-pickup') {
                $data = ShopifyOrdersService::fetchOrdersReadyForPickup();
            } elseif ($view === 'on-hold') {
                $data = ShopifyOrdersService::fetchOrdersOnHold();
            } elseif ($view === 'upcoming') {
                $data = ShopifyOrdersService::fetchOrdersUpcoming();
            } elseif ($request->query('archived') === 'true') {
                $data = ShopifyOrdersService::fetchOrders(true);
            } else {
                $data = ShopifyOrdersService::fetchOrders(false);
            }

            return response()->json($data);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Failed to fetch orders',
                'detail' => $e->getMessage(),
            ], 500);
        }
    }

    public function addOnHoldTag(Request $request, int $orderId): JsonResponse
    {
        try {
            ShopifyOrdersService::addOnHoldTagToOrder($orderId);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function removeOnHoldTag(Request $request, int $orderId): JsonResponse
    {
        try {
            ShopifyOrdersService::removeOnHoldTagFromOrder($orderId);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function readyOrderForPickup(Request $request): JsonResponse
    {
        $request->validate([
            'fulfillment_order_id' => ['required', 'string', 'max:128'],
        ]);
        try {
            ShopifyOrdersService::readyOrderForPickup((string) $request->input('fulfillment_order_id'));
            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function markOrderAsPickedUp(Request $request): JsonResponse
    {
        $request->validate([
            'fulfillment_order_id' => ['required', 'string', 'max:128'],
        ]);
        try {
            ShopifyOrdersService::markOrderAsPickedUp((string) $request->input('fulfillment_order_id'));
            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
