<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppStatus;
use App\Services\BusinessCentralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessCentralLinesController extends Controller
{
    public function store(Request $request, string $bcOrderId): JsonResponse
    {
        if (! AppStatus::isProduction()) {
            return response()->json([
                'ok' => false,
                'error' => 'App is in Test mode; Business Central changes are disabled. '
                . 'Set VITE_APP_STATUS=Production to enable.',
            ], 403);
        }

        if (! BusinessCentralService::isConfigured()) {
            return response()->json([
                'ok' => false,
                'error' => 'Business Central is not configured',
            ], 503);
        }

        $bcOrderId = trim($bcOrderId);
        if ($bcOrderId === '') {
            return response()->json([
                'ok' => false,
                'error' => 'Missing sales order ID',
            ], 400);
        }

        $description = $request->input('description');
        $description = is_string($description) ? trim($description) : '';
        if ($description === '') {
            return response()->json([
                'ok' => false,
                'error' => 'description is required',
            ], 400);
        }

        try {
            $token = BusinessCentralService::getAccessToken();
            $resolved = BusinessCentralService::resolveCompany($token);
            if (! $resolved) {
                return response()->json([
                    'ok' => false,
                    'error' => 'No company found in Business Central',
                ], 500);
            }

            BusinessCentralService::createSalesOrderLine(
                $token,
                $resolved['companyId'],
                $bcOrderId,
                'Comment',
                $description
            );
            // logging activity for BC GIA number
            activity('business_central')
                ->causedBy(auth()->user())
                ->event('bc_line_added')
                ->withProperties([
                    'bc_order_id' => $bcOrderId,
                    'role' => auth()->user()->role,
                    'ip' => request()->ip(),
                    'gia_number' => $description,
                    'user_agent' => request()->userAgent(),
                ])
                ->log('GIA number added to Business Central order');

            return response()->json(['ok' => true]);
        } catch (\Throwable $err) {
            $errorMessage = $err->getMessage();

            activity('business_central')
                ->causedBy(auth()->user())
                ->event('bc_line_failed')
                ->withProperties([
                    'action' => 'add_gia_line',
                    'bc_order_id' => $bcOrderId,
                    'gia_number' => $description,
                    'error' => $errorMessage,
                    'role' => auth()->user()->role,
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ])
                ->log('GIA line failed for BC order ' . $bcOrderId . ': ' . $errorMessage);

            return response()->json([
                'ok' => false,
                'error' => $errorMessage,
            ], 500);
        }
    }
}
