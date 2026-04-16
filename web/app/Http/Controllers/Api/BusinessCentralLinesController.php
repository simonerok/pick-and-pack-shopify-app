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
                    . 'Set APP_STATUS=Production to enable.',
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

            return response()->json(['ok' => true]);
        } catch (\Throwable $err) {
            return response()->json([
                'ok' => false,
                'error' => $err->getMessage(),
            ], 500);
        }
    }
}
