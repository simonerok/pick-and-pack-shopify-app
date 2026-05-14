<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppStatus;
use App\Services\WebshipperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebshipperLabelController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        if (! AppStatus::isProduction()) {
            return response()->json([
                'ok' => false,
                'error' => 'App is in Test mode; label creation is disabled. Set VITE_APP_STATUS=Production to enable.',
            ], 403);
        }

        $orderIdParam = $request->query('orderId');
        $orderId = $orderIdParam !== null ? (int) $orderIdParam : 0;
        if ($orderId < 1) {
            return response()->json([
                'ok' => false,
                'error' => 'Missing or invalid query parameter: orderId (Webshipper order id)',
            ], 400);
        }

        try {
            $result = WebshipperService::getLabelPdfForOrder($orderId);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'error' => 'The shipping label could not be loaded right now. Please try again.',
            ], 502);
        }

        if (! ($result['ok'] ?? false)) {
            $errorMessage = $result['error'] ?? 'Unknown error';
            $status = self::httpStatusForWebshipperError($errorMessage);

            activity('shipping')
                ->causedBy(auth()->user())
                ->event('label_failed')
                ->withProperties([
                    'action' => 'print_shipping_label',
                    'webshipper_order_id' => $orderId,
                    'error' => $errorMessage,
                    'role' => auth()->user()->role,
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ])
                ->log('Shipping label failed for Webshipper order ' . $orderId . ': ' . $errorMessage);

            return response()->json([
                'ok' => false,
                'error' => self::userMessageForWebshipperError($errorMessage),
            ], $status);
        }

        // logging activity for shipping label generation
        activity('shipping')
            ->causedBy(auth()->user())
            ->event('label_generated')
            ->withProperties([
                'ws_order_id' => $orderId,
                'role' => auth()->user()->role,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ])
            ->log('Shipping label generated, for Webshipper order id: ' . $orderId);

        return response()->json(['ok' => true, 'pdfBase64' => $result['pdfBase64']]);
    }

    /**
     * Map WebshipperService messages like "Create shipment failed: 403 …" to an HTTP status for this app.
     */
    private static function httpStatusForWebshipperError(string $errorMessage): int
    {
        if (preg_match('/failed:\s*(\d{3})\b/', $errorMessage, $m)) {
            $upstream = (int) $m[1];

            return match (true) {
                $upstream >= 500 => 502,
                $upstream === 403, $upstream === 401 => $upstream,
                default => 400,
            };
        }

        return 400;
    }

    private static function userMessageForWebshipperError(string $errorMessage): string
    {
        $message = strtolower($errorMessage);

        if (str_contains($message, '403') || str_contains($message, '401') || str_contains($message, 'scope')) {
            return 'The app is missing Webshipper permissions needed to create labels. '
                . 'Check Webshipper access, then try again.';
        }

        if (str_contains($message, 'not available') || str_contains($message, 'not yet')) {
            return 'The shipping label is not ready yet. Please try again in a moment.';
        }

        return 'The shipping label could not be loaded right now. Please try again.';
    }
}
