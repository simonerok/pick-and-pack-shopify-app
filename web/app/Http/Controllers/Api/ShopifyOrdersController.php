<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ShopifyOrdersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

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
        } catch (Throwable $e) {
            return self::errorResponse(
                $e,
                'orders',
                'Order data could not be loaded right now. Please refresh and try again.',
                500
            );
        }
    }

    public function addOnHoldTag(Request $request, int $orderId): JsonResponse
    {
        try {
            ShopifyOrdersService::addOnHoldTagToOrder($orderId);

            return response()->json(['ok' => true]);
        } catch (Throwable $e) {
            return self::errorResponse(
                $e,
                'shopify_tag',
                'The Shopify order tag could not be updated. Please refresh and try again.',
                422
            );
        }
    }

    public function removeOnHoldTag(Request $request, int $orderId): JsonResponse
    {
        try {
            ShopifyOrdersService::removeOnHoldTagFromOrder($orderId);

            return response()->json(['ok' => true]);
        } catch (Throwable $e) {
            return self::errorResponse(
                $e,
                'shopify_tag',
                'The Shopify order tag could not be updated. Please refresh and try again.',
                422
            );
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
        } catch (Throwable $e) {
            return self::errorResponse(
                $e,
                'shopify_fulfillment',
                'The pickup status could not be updated. Please check the app permissions and try again.',
                422
            );
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
        } catch (Throwable $e) {
            return self::errorResponse(
                $e,
                'shopify_fulfillment',
                'The pickup status could not be updated. Please check the app permissions and try again.',
                422
            );
        }
    }

    private static function errorResponse(
        Throwable $exception,
        string $context,
        string $fallbackMessage,
        int $status
    ): JsonResponse {
        report($exception);

        return response()->json([
            'ok' => false,
            'error' => self::userMessageForException($exception, $context, $fallbackMessage),
        ], $status);
    }

    private static function userMessageForException(
        Throwable $exception,
        string $context,
        string $fallbackMessage
    ): string {
        $message = strtolower($exception->getMessage());
        $isPermissionError = str_contains($message, 'access denied')
            || str_contains($message, 'permission')
            || str_contains($message, 'scope')
            || str_contains($message, '403');

        if ($isPermissionError) {
            if (str_contains($message, 'fulfillment')) {
                return 'The app is missing Shopify permissions for fulfillment data. '
                    . 'Reinstall the app with the updated permissions, then try again.';
            }

            return 'The app is missing a Shopify permission. Check the app permissions, then try again.';
        }

        if (
            str_contains($message, 'graphql error')
            || str_contains($message, 'shopify api error')
            || str_contains($message, 'timeout')
            || str_contains($message, 'timed out')
        ) {
            return $context === 'orders'
                ? 'Order data could not be loaded from Shopify right now. Please refresh and try again.'
                : $fallbackMessage;
        }

        return $fallbackMessage;
    }
}
