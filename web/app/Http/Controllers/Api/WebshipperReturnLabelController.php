<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppStatus;
use App\Services\WebshipperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebshipperReturnLabelController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        if (! AppStatus::isProduction()) {
            return response()->json([
                'ok' => false,
                'error' => 'App is in Test mode; return label creation is disabled. '
                . 'Set VITE_APP_STATUS=Production to enable.',
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
            $result = WebshipperService::getReturnLabelPdfForOrder($orderId);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'error' => 'The return label could not be created right now. Please try again.',
            ], 502);
        }

        if (! ($result['ok'] ?? false)) {
            $err = $result['error'] ?? '';
            $status = (str_contains($err, 'failed') || str_contains($err, 'not yet')) ? 502 : 400;
            $errorMessage = $err ?: 'Unknown error';

            // logging error for return label generation
            activity('shipping')
                ->causedBy(auth()->user())
                ->event('return_label_failed')
                ->withProperties([
                    'action' => 'create_return_label',
                    'webshipper_order_id' => $orderId,
                    'error' => $errorMessage,
                    'role' => auth()->user()->role,
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ])
                ->log('Return label failed for Webshipper order ' . $orderId . ': ' . $errorMessage);

            return response()->json([
                'ok' => false,
                'error' => self::userMessageForWebshipperError($errorMessage),
            ], $status);
        }
        // logging activity for return label generation
        activity('shipping')
            ->causedBy(auth()->user())
            ->event('return_label_generated')
            ->withProperties([
                'ws_order_id' => $orderId,
                'role' => auth()->user()->role,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ])
            ->log('Return label generated, for Webshipper order id: ' . $orderId);

        return response()->json(['ok' => true, 'pdfBase64' => $result['pdfBase64']]);
    }

    private static function userMessageForWebshipperError(string $errorMessage): string
    {
        $message = strtolower($errorMessage);

        if (str_contains($message, '403') || str_contains($message, '401') || str_contains($message, 'scope')) {
            return 'The app is missing Webshipper permissions needed to create return labels. '
                . 'Check Webshipper access, then try again.';
        }

        if (str_contains($message, 'not available') || str_contains($message, 'not yet')) {
            return 'The return label is not ready yet. Please try again in a moment.';
        }

        return 'The return label could not be created right now. Please try again.';
    }
}
